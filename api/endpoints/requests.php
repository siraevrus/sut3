<?php
/**
 * Requests API Endpoint
 * Система складского учета (SUT)
 */

ApiAuth::requireAuth();

switch ($method) {
    case 'GET':
        if (isset($pathParts[1])) {
            // GET /api/requests/{id}
            getRequest($pathParts[1]);
        } else {
            // GET /api/requests
            getRequests();
        }
        break;
        
    case 'POST':
        if (isset($pathParts[1]) && $pathParts[1] === 'process') {
            // POST /api/requests/process
            processRequest();
        } else {
            // POST /api/requests
            createRequest();
        }
        break;
        
    case 'PUT':
        if (isset($pathParts[1])) {
            // PUT /api/requests/{id}
            updateRequest($pathParts[1]);
        } else {
            ApiResponse::error('Request ID required', 400);
        }
        break;
        
    default:
        ApiResponse::error('Method not allowed', 405);
}

/**
 * Получить список запросов
 */
function getRequests() {
    try {
        $pdo = getDBConnection();
        $user = getCurrentUser();
        
        // Параметры фильтрации
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $status = $_GET['status'] ?? '';
        $template_id = (int)($_GET['template_id'] ?? 0);
        $warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
        
        // Строим запрос с учетом прав доступа
        $where = ['1=1'];
        $bindings = [];
        
        // Права доступа по ролям
        if ($user['role'] === ROLE_WAREHOUSE_WORKER) {
            $where[] = "r.created_by = ?";
            $bindings[] = $user['id'];
        } elseif ($user['role'] === ROLE_SALES_MANAGER) {
            // Менеджер по продажам видит все запросы
        } elseif ($user['role'] === ROLE_ADMIN) {
            // Администратор видит все запросы
        } else {
            ApiResponse::error('Access denied', 403);
            return;
        }
        
        // Фильтры
        if ($status !== '') {
            $where[] = "r.status = ?";
            $bindings[] = $status;
        }
        
        if ($template_id > 0) {
            $where[] = "r.template_id = ?";
            $bindings[] = $template_id;
        }
        
        if ($warehouse_id > 0) {
            $where[] = "r.warehouse_id = ?";
            $bindings[] = $warehouse_id;
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Получаем данные
        $query = "
            SELECT 
                r.*,
                pt.name as template_name,
                u.first_name, u.last_name,
                w.name as warehouse_name,
                c.name as company_name
            FROM requests r
            LEFT JOIN product_templates pt ON r.template_id = pt.id
            LEFT JOIN users u ON r.created_by = u.id
            LEFT JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN companies c ON w.company_id = c.id
            WHERE $whereClause
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([...$bindings, $limit, $offset]);
        $requests = $stmt->fetchAll();
        
        // Подсчитываем общее количество
        $countQuery = "SELECT COUNT(*) FROM requests r WHERE $whereClause";
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute($bindings);
        $total = $stmt->fetchColumn();
        
        // Обрабатываем данные для API
        foreach ($requests as &$request) {
            $request['requested_attributes'] = json_decode($request['requested_attributes'], true) ?: [];
            $request['quantity'] = (float)$request['quantity'];
        }
        
        ApiResponse::success([
            'requests' => $requests,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        logError('API Requests list error: ' . $e->getMessage());
        ApiResponse::error('Failed to fetch requests', 500);
    }
}

/**
 * Получить конкретный запрос
 */
function getRequest($id) {
    try {
        $requestId = (int)$id;
        if ($requestId <= 0) {
            ApiResponse::error('Invalid request ID', 400);
            return;
        }
        
        $pdo = getDBConnection();
        $user = getCurrentUser();
        
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                pt.name as template_name,
                pt.description as template_description,
                u.first_name, u.last_name, u.role as creator_role,
                w.name as warehouse_name,
                c.name as company_name,
                processor.first_name as processor_first_name,
                processor.last_name as processor_last_name
            FROM requests r
            LEFT JOIN product_templates pt ON r.template_id = pt.id
            LEFT JOIN users u ON r.created_by = u.id
            LEFT JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN companies c ON w.company_id = c.id
            LEFT JOIN users processor ON r.processed_by = processor.id
            WHERE r.id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            ApiResponse::error('Request not found', 404);
            return;
        }
        
        // Проверяем права доступа
        $canView = false;
        if ($user['role'] === ROLE_ADMIN || $user['role'] === ROLE_SALES_MANAGER) {
            $canView = true;
        } elseif ($user['role'] === ROLE_WAREHOUSE_WORKER) {
            $canView = ($request['created_by'] == $user['id']);
        }
        
        if (!$canView) {
            ApiResponse::error('Access denied', 403);
            return;
        }
        
        // Обрабатываем данные
        $request['requested_attributes'] = json_decode($request['requested_attributes'], true) ?: [];
        $request['quantity'] = (float)$request['quantity'];
        
        ApiResponse::success(['request' => $request]);
        
    } catch (Exception $e) {
        logError('API Request get error: ' . $e->getMessage());
        ApiResponse::error('Failed to fetch request', 500);
    }
}

/**
 * Создать новый запрос
 */
function createRequest() {
    try {
        $user = getCurrentUser();
        
        // Проверяем права доступа
        if (!in_array($user['role'], [ROLE_WAREHOUSE_WORKER, ROLE_SALES_MANAGER])) {
            ApiResponse::error('Access denied', 403);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            ApiResponse::error('Invalid JSON input', 400);
            return;
        }
        
        // Валидация
        $required = ['template_id', 'warehouse_id', 'quantity', 'requested_attributes'];
        foreach ($required as $field) {
            if (!isset($input[$field])) {
                ApiResponse::error("Field '$field' is required", 400);
                return;
            }
        }
        
        $pdo = getDBConnection();
        
        // Проверяем доступ к шаблону и складу
        $stmt = $pdo->prepare("SELECT id FROM product_templates WHERE id = ? AND status = 1");
        $stmt->execute([$input['template_id']]);
        if (!$stmt->fetch()) {
            ApiResponse::error('Template not found', 404);
            return;
        }
        
        // Создаем запрос
        $stmt = $pdo->prepare("
            INSERT INTO requests (
                template_id, warehouse_id, quantity, delivery_date, 
                description, requested_attributes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $input['template_id'],
            $input['warehouse_id'],
            $input['quantity'],
            $input['delivery_date'] ?? null,
            $input['description'] ?? null,
            json_encode($input['requested_attributes'], JSON_UNESCAPED_UNICODE),
            $user['id']
        ]);
        
        $requestId = $pdo->lastInsertId();
        
        logInfo('Request created via API', [
            'request_id' => $requestId,
            'created_by' => $user['id']
        ]);
        
        ApiResponse::success(['request_id' => $requestId], 'Request created successfully', 201);
        
    } catch (Exception $e) {
        logError('API Request create error: ' . $e->getMessage());
        ApiResponse::error('Failed to create request', 500);
    }
}

/**
 * Обновить запрос
 */
function updateRequest($id) {
    try {
        $requestId = (int)$id;
        if ($requestId <= 0) {
            ApiResponse::error('Invalid request ID', 400);
            return;
        }
        
        $user = getCurrentUser();
        $pdo = getDBConnection();
        
        // Проверяем существование и права доступа
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            ApiResponse::error('Request not found', 404);
            return;
        }
        
        if ($request['status'] === 'processed') {
            ApiResponse::error('Cannot edit processed request', 400);
            return;
        }
        
        if (!in_array($user['role'], [ROLE_WAREHOUSE_WORKER, ROLE_SALES_MANAGER]) || $request['created_by'] != $user['id']) {
            ApiResponse::error('Access denied', 403);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            ApiResponse::error('Invalid JSON input', 400);
            return;
        }
        
        // Обновляем запрос
        $updateFields = [];
        $bindings = [];
        
        $allowedFields = ['template_id', 'warehouse_id', 'quantity', 'delivery_date', 'description', 'requested_attributes'];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "$field = ?";
                if ($field === 'requested_attributes') {
                    $bindings[] = json_encode($input[$field], JSON_UNESCAPED_UNICODE);
                } else {
                    $bindings[] = $input[$field];
                }
            }
        }
        
        if (empty($updateFields)) {
            ApiResponse::error('No fields to update', 400);
            return;
        }
        
        $bindings[] = $requestId;
        $bindings[] = $user['id'];
        
        $stmt = $pdo->prepare("
            UPDATE requests 
            SET " . implode(', ', $updateFields) . "
            WHERE id = ? AND created_by = ?
        ");
        $stmt->execute($bindings);
        
        logInfo('Request updated via API', [
            'request_id' => $requestId,
            'updated_by' => $user['id']
        ]);
        
        ApiResponse::success([], 'Request updated successfully');
        
    } catch (Exception $e) {
        logError('API Request update error: ' . $e->getMessage());
        ApiResponse::error('Failed to update request', 500);
    }
}

/**
 * Обработать запрос (изменить статус)
 */
function processRequest() {
    try {
        $user = getCurrentUser();
        
        // Только администратор может обрабатывать запросы
        if ($user['role'] !== ROLE_ADMIN) {
            ApiResponse::error('Access denied', 403);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['request_id']) || !isset($input['action'])) {
            ApiResponse::error('request_id and action are required', 400);
            return;
        }
        
        $requestId = (int)$input['request_id'];
        $action = $input['action'];
        
        if (!in_array($action, ['process', 'unprocess'])) {
            ApiResponse::error('Invalid action', 400);
            return;
        }
        
        $pdo = getDBConnection();
        
        // Проверяем существование запроса
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            ApiResponse::error('Request not found', 404);
            return;
        }
        
        if ($action === 'process') {
            if ($request['status'] === 'processed') {
                ApiResponse::error('Request already processed', 400);
                return;
            }
            
            $stmt = $pdo->prepare("
                UPDATE requests 
                SET status = 'processed', processed_by = ?, processed_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$user['id'], $requestId]);
            
            $message = 'Request processed successfully';
        } else {
            if ($request['status'] === 'pending') {
                ApiResponse::error('Request already pending', 400);
                return;
            }
            
            $stmt = $pdo->prepare("
                UPDATE requests 
                SET status = 'pending', processed_by = NULL, processed_at = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$requestId]);
            
            $message = 'Request returned to pending status';
        }
        
        logInfo('Request status changed via API', [
            'request_id' => $requestId,
            'action' => $action,
            'changed_by' => $user['id']
        ]);
        
        ApiResponse::success([], $message);
        
    } catch (Exception $e) {
        logError('API Request process error: ' . $e->getMessage());
        ApiResponse::error('Failed to process request', 500);
    }
}
?>