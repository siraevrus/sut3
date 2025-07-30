<?php
/**
 * Transit Endpoint
 * Система складского учета (SUT)
 */

ApiAuth::requireAuth();

$currentUser = ApiAuth::getCurrentUser();

switch ($method) {
    case 'GET':
        if (isset($pathParts[1])) {
            // GET /api/transit/{id} - получить конкретную отправку
            getTransitItem($pathParts[1]);
        } else {
            // GET /api/transit - получить список отправок
            getTransitList();
        }
        break;
        
    case 'POST':
        if (isset($pathParts[1]) && $pathParts[1] === 'status') {
            // POST /api/transit/status - обновить статус отправки
            updateTransitStatus();
        } else {
            // POST /api/transit - создать новую отправку
            createTransit();
        }
        break;
        
    case 'PUT':
        if (isset($pathParts[1])) {
            // PUT /api/transit/{id} - обновить отправку
            updateTransit($pathParts[1]);
        } else {
            ApiResponse::error('Transit ID required', 400);
        }
        break;
        
    case 'DELETE':
        if (isset($pathParts[1])) {
            // DELETE /api/transit/{id} - удалить отправку
            deleteTransit($pathParts[1]);
        } else {
            ApiResponse::error('Transit ID required', 400);
        }
        break;
        
    default:
        ApiResponse::error('Method not allowed', 405);
}

/**
 * Получить список отправок
 */
function getTransitList() {
    global $currentUser;
    
    try {
        $pdo = getDBConnection();
        
        // Параметры фильтрации
        $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
        $offset = ($page - 1) * $limit;
        
        // Строим условия WHERE с учетом прав доступа
        $whereConditions = [];
        $params = [];
        
        // Ограничения по роли пользователя
        if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
            $whereConditions[] = "git.warehouse_id = ?";
            $params[] = $currentUser['warehouse_id'];
        }
        
        // Фильтр по складу
        if ($warehouseId > 0) {
            $whereConditions[] = "git.warehouse_id = ?";
            $params[] = $warehouseId;
        }
        
        // Фильтр по статусу
        if (!empty($status)) {
            $whereConditions[] = "git.status = ?";
            $params[] = $status;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Подсчитываем общее количество записей
        $countQuery = "
            SELECT COUNT(*) 
            FROM goods_in_transit git
            JOIN warehouses w ON git.warehouse_id = w.id
            $whereClause
        ";
        
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute($params);
        $totalRecords = $stmt->fetchColumn();
        
        // Получаем данные с пагинацией
        $query = "
            SELECT 
                git.id,
                git.departure_location,
                git.departure_date,
                git.arrival_date,
                git.status,
                git.created_at,
                git.confirmed_at,
                w.name as warehouse_name,
                c.name as company_name,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                CONCAT(uc.first_name, ' ', uc.last_name) as confirmed_by_name,
                JSON_LENGTH(git.goods_info) as goods_count,
                CASE 
                    WHEN git.files IS NOT NULL THEN JSON_LENGTH(git.files) 
                    ELSE 0 
                END as files_count
            FROM goods_in_transit git
            JOIN warehouses w ON git.warehouse_id = w.id
            JOIN companies c ON w.company_id = c.id
            JOIN users u ON git.created_by = u.id
            LEFT JOIN users uc ON git.confirmed_by = uc.id
            $whereClause
            ORDER BY 
                CASE git.status 
                    WHEN 'in_transit' THEN 1 
                    WHEN 'arrived' THEN 2 
                    WHEN 'confirmed' THEN 3 
                END,
                git.departure_date DESC, 
                git.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([...$params, $limit, $offset]);
        $transitItems = $stmt->fetchAll();
        
        ApiResponse::success([
            'items' => $transitItems,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($totalRecords / $limit),
                'total_records' => $totalRecords,
                'per_page' => $limit
            ]
        ]);
        
    } catch (Exception $e) {
        logError('API Transit list error: ' . $e->getMessage());
        ApiResponse::error('Failed to fetch transit items', 500);
    }
}

/**
 * Получить конкретную отправку
 */
function getTransitItem($transitId) {
    global $currentUser;
    
    try {
        $pdo = getDBConnection();
        
        $query = "
            SELECT 
                git.*,
                w.name as warehouse_name,
                w.address as warehouse_address,
                c.name as company_name,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                CONCAT(uc.first_name, ' ', uc.last_name) as confirmed_by_name
            FROM goods_in_transit git
            JOIN warehouses w ON git.warehouse_id = w.id
            JOIN companies c ON w.company_id = c.id
            JOIN users u ON git.created_by = u.id
            LEFT JOIN users uc ON git.confirmed_by = uc.id
            WHERE git.id = ?
        ";
        
        // Ограичиваем доступ для работника склада
        if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
            $query .= " AND git.warehouse_id = " . $currentUser['warehouse_id'];
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$transitId]);
        $transit = $stmt->fetch();
        
        if (!$transit) {
            ApiResponse::error('Transit item not found', 404);
        }
        
        // Декодируем JSON поля
        $transit['goods_info'] = json_decode($transit['goods_info'], true) ?: [];
        $transit['files'] = json_decode($transit['files'], true) ?: [];
        
        ApiResponse::success($transit);
        
    } catch (Exception $e) {
        logError('API Transit get error: ' . $e->getMessage());
        ApiResponse::error('Failed to fetch transit item', 500);
    }
}

/**
 * Создать новую отправку
 */
function createTransit() {
    global $currentUser;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Валидация входных данных
        $requiredFields = ['departure_location', 'departure_date', 'arrival_date', 'warehouse_id', 'goods_info'];
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                ApiResponse::error("Field '$field' is required", 400);
            }
        }
        
        // Проверяем доступ к складу
        if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
            if ($input['warehouse_id'] != $currentUser['warehouse_id']) {
                ApiResponse::error('Access denied to selected warehouse', 403);
            }
        }
        
        $pdo = getDBConnection();
        $pdo->beginTransaction();
        
        $insertQuery = "
            INSERT INTO goods_in_transit (
                departure_location, departure_date, arrival_date, 
                warehouse_id, goods_info, files, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $pdo->prepare($insertQuery);
        $stmt->execute([
            $input['departure_location'],
            $input['departure_date'],
            $input['arrival_date'],
            $input['warehouse_id'],
            json_encode($input['goods_info'], JSON_UNESCAPED_UNICODE),
            isset($input['files']) ? json_encode($input['files'], JSON_UNESCAPED_UNICODE) : null,
            $currentUser['id']
        ]);
        
        $transitId = $pdo->lastInsertId();
        
        $pdo->commit();
        
        ApiResponse::success(['id' => $transitId], 'Transit item created successfully', 201);
        
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        logError('API Transit create error: ' . $e->getMessage());
        ApiResponse::error('Failed to create transit item', 500);
    }
}

/**
 * Обновить отправку
 */
function updateTransit($transitId) {
    global $currentUser;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $pdo = getDBConnection();
        
        // Проверяем существование и права доступа
        $checkQuery = "
            SELECT id, status, warehouse_id 
            FROM goods_in_transit 
            WHERE id = ? AND status != 'confirmed'
        ";
        
        if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
            $checkQuery .= " AND warehouse_id = " . $currentUser['warehouse_id'];
        }
        
        $stmt = $pdo->prepare($checkQuery);
        $stmt->execute([$transitId]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            ApiResponse::error('Transit item not found or cannot be edited', 404);
        }
        
        $pdo->beginTransaction();
        
        // Подготавливаем данные для обновления
        $updateFields = [];
        $params = [];
        
        if (isset($input['departure_location'])) {
            $updateFields[] = "departure_location = ?";
            $params[] = $input['departure_location'];
        }
        
        if (isset($input['departure_date'])) {
            $updateFields[] = "departure_date = ?";
            $params[] = $input['departure_date'];
        }
        
        if (isset($input['arrival_date'])) {
            $updateFields[] = "arrival_date = ?";
            $params[] = $input['arrival_date'];
        }
        
        if (isset($input['warehouse_id'])) {
            // Проверяем доступ к новому складу
            if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
                if ($input['warehouse_id'] != $currentUser['warehouse_id']) {
                    ApiResponse::error('Access denied to selected warehouse', 403);
                }
            }
            $updateFields[] = "warehouse_id = ?";
            $params[] = $input['warehouse_id'];
        }
        
        if (isset($input['goods_info'])) {
            $updateFields[] = "goods_info = ?";
            $params[] = json_encode($input['goods_info'], JSON_UNESCAPED_UNICODE);
        }
        
        if (isset($input['files'])) {
            $updateFields[] = "files = ?";
            $params[] = json_encode($input['files'], JSON_UNESCAPED_UNICODE);
        }
        
        if (empty($updateFields)) {
            ApiResponse::error('No fields to update', 400);
        }
        
        $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $transitId;
        
        $updateQuery = "UPDATE goods_in_transit SET " . implode(', ', $updateFields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute($params);
        
        $pdo->commit();
        
        ApiResponse::success(['id' => $transitId], 'Transit item updated successfully');
        
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        logError('API Transit update error: ' . $e->getMessage());
        ApiResponse::error('Failed to update transit item', 500);
    }
}

/**
 * Обновить статус отправки
 */
function updateTransitStatus() {
    global $currentUser;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['id']) || empty($input['status'])) {
            ApiResponse::error('Transit ID and status are required', 400);
        }
        
        $allowedStatuses = ['in_transit', 'arrived', 'confirmed'];
        if (!in_array($input['status'], $allowedStatuses)) {
            ApiResponse::error('Invalid status', 400);
        }
        
        $pdo = getDBConnection();
        
        // Проверяем существование и права доступа
        $checkQuery = "
            SELECT id, status, warehouse_id 
            FROM goods_in_transit 
            WHERE id = ?
        ";
        
        if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
            $checkQuery .= " AND warehouse_id = " . $currentUser['warehouse_id'];
        }
        
        $stmt = $pdo->prepare($checkQuery);
        $stmt->execute([$input['id']]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            ApiResponse::error('Transit item not found', 404);
        }
        
        // Проверяем логику смены статуса
        $validTransitions = [
            'in_transit' => ['arrived'],
            'arrived' => ['confirmed'],
            'confirmed' => [] // Нельзя изменить подтвержденный статус
        ];
        
        if (!in_array($input['status'], $validTransitions[$existing['status']])) {
            ApiResponse::error('Invalid status transition', 400);
        }
        
        $pdo->beginTransaction();
        
        $updateQuery = "
            UPDATE goods_in_transit 
            SET status = ?, updated_at = CURRENT_TIMESTAMP
        ";
        $params = [$input['status']];
        
        // Если статус "подтвержден", добавляем информацию о подтверждении
        if ($input['status'] === 'confirmed') {
            $updateQuery .= ", confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP";
            $params[] = $currentUser['id'];
        }
        
        $updateQuery .= " WHERE id = ?";
        $params[] = $input['id'];
        
        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute($params);
        
        $pdo->commit();
        
        ApiResponse::success(['id' => $input['id']], 'Status updated successfully');
        
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        logError('API Transit status update error: ' . $e->getMessage());
        ApiResponse::error('Failed to update status', 500);
    }
}

/**
 * Удалить отправку
 */
function deleteTransit($transitId) {
    global $currentUser;
    
    try {
        $pdo = getDBConnection();
        
        // Проверяем существование и права доступа
        $checkQuery = "
            SELECT id, status, warehouse_id, files 
            FROM goods_in_transit 
            WHERE id = ? AND status != 'confirmed'
        ";
        
        if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
            $checkQuery .= " AND warehouse_id = " . $currentUser['warehouse_id'];
        }
        
        $stmt = $pdo->prepare($checkQuery);
        $stmt->execute([$transitId]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            ApiResponse::error('Transit item not found or cannot be deleted', 404);
        }
        
        $pdo->beginTransaction();
        
        // Удаляем запись из базы
        $deleteQuery = "DELETE FROM goods_in_transit WHERE id = ?";
        $stmt = $pdo->prepare($deleteQuery);
        $stmt->execute([$transitId]);
        
        // Удаляем связанные файлы
        if (!empty($existing['files'])) {
            $files = json_decode($existing['files'], true);
            foreach ($files as $file) {
                if (file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
            }
        }
        
        $pdo->commit();
        
        ApiResponse::success([], 'Transit item deleted successfully');
        
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        logError('API Transit delete error: ' . $e->getMessage());
        ApiResponse::error('Failed to delete transit item', 500);
    }
}

?>