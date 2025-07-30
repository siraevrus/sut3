<?php
/**
 * API endpoint для работы с приемкой товаров
 */

require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/api_response.php';

// Требуется авторизация для всех операций
ApiAuth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';
$pathParts = array_values(array_filter(explode('/', $path)));

try {
    switch ($method) {
        case 'GET':
            if (empty($pathParts)) {
                // GET /api/receiving - получить список товаров для приемки
                getReceivingList();
            } elseif (count($pathParts) === 1 && is_numeric($pathParts[0])) {
                // GET /api/receiving/{id} - получить детали товара для приемки
                getReceivingItem((int)$pathParts[0]);
            } else {
                ApiResponse::notFound('Endpoint not found');
            }
            break;
            
        case 'POST':
            if (count($pathParts) === 1 && $pathParts[0] === 'confirm') {
                // POST /api/receiving/confirm - подтвердить приемку
                confirmReceiving();
            } else {
                ApiResponse::notFound('Endpoint not found');
            }
            break;
            
        default:
            ApiResponse::methodNotAllowed('Method not allowed');
    }
} catch (Exception $e) {
    logError('API Receiving error: ' . $e->getMessage());
    ApiResponse::serverError('Internal server error');
}

/**
 * Получить список товаров для приемки
 */
function getReceivingList() {
    try {
        $pdo = getDBConnection();
        $currentUser = ApiAuth::getCurrentUser();
        
        // Параметры фильтрации
        $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
        $status = $_GET['status'] ?? 'confirmed';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        // Проверка прав доступа
        if (!in_array($currentUser['role'], ['admin', 'warehouse_worker'])) {
            ApiResponse::forbidden('Access denied');
        }
        
        // Построение условий WHERE
        $whereConditions = [];
        $params = [];
        
        if ($status) {
            $whereConditions[] = "gt.status = :status";
            $params['status'] = $status;
        }
        
        // Ограничение по складу для работников склада
        if ($currentUser['role'] === 'warehouse_worker' && !empty($currentUser['warehouse_id'])) {
            $whereConditions[] = "gt.warehouse_id = :user_warehouse_id";
            $params['user_warehouse_id'] = $currentUser['warehouse_id'];
        } elseif ($warehouseId) {
            $whereConditions[] = "gt.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }
        
        $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Подсчет общего количества
        $countQuery = "
            SELECT COUNT(*) as total
            FROM goods_in_transit gt
            $whereClause
        ";
        
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch()['total'];
        
        // Основной запрос
        $query = "
            SELECT 
                gt.id,
                gt.departure_date,
                gt.arrival_date,
                gt.departure_location,
                gt.arrival_location,
                gt.goods_info,
                gt.status,
                gt.notes,
                gt.created_at,
                gt.confirmed_at,
                w.name as warehouse_name,
                w.address as warehouse_address,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                u.login as created_by_login,
                CONCAT(uc.first_name, ' ', uc.last_name) as confirmed_by_name,
                uc.login as confirmed_by_login
            FROM goods_in_transit gt
            LEFT JOIN warehouses w ON gt.warehouse_id = w.id
            LEFT JOIN users u ON gt.created_by = u.id
            LEFT JOIN users uc ON gt.confirmed_by = uc.id
            $whereClause
            ORDER BY gt.arrival_date ASC, gt.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $pdo->prepare($query);
        
        // Привязываем параметры
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $items = $stmt->fetchAll();
        
        // Обработка данных
        foreach ($items as &$item) {
            $item['goods_info'] = json_decode($item['goods_info'], true) ?: [];
            $item['total_quantity'] = array_sum(array_column($item['goods_info'], 'quantity'));
            $item['items_count'] = count($item['goods_info']);
        }
        
        ApiResponse::success([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        logError('Error getting receiving list: ' . $e->getMessage());
        ApiResponse::serverError('Failed to get receiving list');
    }
}

/**
 * Получить детали товара для приемки
 */
function getReceivingItem($transitId) {
    try {
        $pdo = getDBConnection();
        $currentUser = ApiAuth::getCurrentUser();
        
        // Проверка прав доступа
        if (!in_array($currentUser['role'], ['admin', 'warehouse_worker'])) {
            ApiResponse::forbidden('Access denied');
        }
        
        $query = "
            SELECT 
                gt.*,
                w.name as warehouse_name,
                w.address as warehouse_address,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                u.login as created_by_login,
                CONCAT(uc.first_name, ' ', uc.last_name) as confirmed_by_name,
                uc.login as confirmed_by_login
            FROM goods_in_transit gt
            LEFT JOIN warehouses w ON gt.warehouse_id = w.id
            LEFT JOIN users u ON gt.created_by = u.id
            LEFT JOIN users uc ON gt.confirmed_by = uc.id
            WHERE gt.id = :id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['id' => $transitId]);
        $item = $stmt->fetch();
        
        if (!$item) {
            ApiResponse::notFound('Transit item not found');
        }
        
        // Проверка прав доступа к конкретному складу
        if ($currentUser['role'] === 'warehouse_worker' && 
            !empty($currentUser['warehouse_id']) && 
            $item['warehouse_id'] != $currentUser['warehouse_id']) {
            ApiResponse::forbidden('Access denied to this warehouse');
        }
        
        // Декодирование JSON данных
        $item['goods_info'] = json_decode($item['goods_info'], true) ?: [];
        $item['files'] = json_decode($item['files'], true) ?: [];
        
        ApiResponse::success($item);
        
    } catch (Exception $e) {
        logError('Error getting receiving item: ' . $e->getMessage());
        ApiResponse::serverError('Failed to get receiving item');
    }
}

/**
 * Подтвердить приемку товара
 */
function confirmReceiving() {
    try {
        $pdo = getDBConnection();
        $currentUser = ApiAuth::getCurrentUser();
        
        // Проверка прав доступа
        if (!in_array($currentUser['role'], ['admin', 'warehouse_worker'])) {
            ApiResponse::forbidden('Access denied');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            ApiResponse::badRequest('Invalid JSON input');
        }
        
        // Валидация входных данных
        $transitId = (int)($input['transit_id'] ?? 0);
        $notes = trim($input['notes'] ?? '');
        $damagedGoods = trim($input['damaged_goods'] ?? '');
        
        if (!$transitId) {
            ApiResponse::badRequest('Transit ID is required');
        }
        
        // Получение информации о товаре в пути
        $query = "
            SELECT 
                gt.*,
                w.name as warehouse_name
            FROM goods_in_transit gt
            LEFT JOIN warehouses w ON gt.warehouse_id = w.id
            WHERE gt.id = :id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['id' => $transitId]);
        $transit = $stmt->fetch();
        
        if (!$transit) {
            ApiResponse::notFound('Transit item not found');
        }
        
        // Проверка прав доступа к конкретному складу
        if ($currentUser['role'] === 'warehouse_worker' && 
            !empty($currentUser['warehouse_id']) && 
            $transit['warehouse_id'] != $currentUser['warehouse_id']) {
            ApiResponse::forbidden('Access denied to this warehouse');
        }
        
        // Проверка статуса - можно подтверждать только товары со статусом 'confirmed'
        if ($transit['status'] !== 'confirmed') {
            ApiResponse::badRequest('Can only confirm items with confirmed status. Current status: ' . $transit['status']);
        }
        
        // Декодирование информации о товарах
        $goodsInfo = json_decode($transit['goods_info'], true) ?: [];
        
        if (empty($goodsInfo)) {
            ApiResponse::badRequest('No goods information found');
        }
        
        $pdo->beginTransaction();
        
        try {
            // Обновляем статус товара в пути
            $updateQuery = "
                UPDATE goods_in_transit 
                SET 
                    status = 'received',
                    confirmed_by = :confirmed_by,
                    confirmed_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), :notes_separator, :notes)
                WHERE id = :id
            ";
            
            $stmt = $pdo->prepare($updateQuery);
            $stmt->execute([
                'id' => $transitId,
                'confirmed_by' => $currentUser['id'],
                'notes_separator' => $transit['notes'] ? "\n\n--- Приемка ---\n" : "--- Приемка ---\n",
                'notes' => $notes . ($damagedGoods ? "\nПоврежденные товары: " . $damagedGoods : '')
            ]);
            
            $addedItems = [];
            
            // Добавляем товары в остатки склада
            foreach ($goodsInfo as $item) {
                if (empty($item['template_id']) || empty($item['quantity'])) {
                    continue;
                }
                
                $templateId = (int)$item['template_id'];
                $quantity = (float)$item['quantity'];
                $attributes = $item['attributes'] ?? [];
                
                // Вычисляем хэш атрибутов
                $attributesHash = hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS));
                
                // Проверяем, есть ли уже такая запись в остатках
                $checkQuery = "
                    SELECT id, quantity 
                    FROM inventory 
                    WHERE warehouse_id = :warehouse_id 
                      AND template_id = :template_id 
                      AND product_attributes_hash = :attributes_hash
                ";
                
                $checkStmt = $pdo->prepare($checkQuery);
                $checkStmt->execute([
                    'warehouse_id' => $transit['warehouse_id'],
                    'template_id' => $templateId,
                    'attributes_hash' => $attributesHash
                ]);
                
                $existingInventory = $checkStmt->fetch();
                
                if ($existingInventory) {
                    // Обновляем существующую запись
                    $updateInventoryQuery = "
                        UPDATE inventory 
                        SET quantity = quantity + :quantity,
                            updated_at = NOW()
                        WHERE id = :id
                    ";
                    
                    $updateInventoryStmt = $pdo->prepare($updateInventoryQuery);
                    $updateInventoryStmt->execute([
                        'quantity' => $quantity,
                        'id' => $existingInventory['id']
                    ]);
                    
                    $addedItems[] = [
                        'inventory_id' => $existingInventory['id'],
                        'template_id' => $templateId,
                        'quantity' => $quantity,
                        'previous_quantity' => $existingInventory['quantity'],
                        'new_quantity' => $existingInventory['quantity'] + $quantity,
                        'action' => 'updated'
                    ];
                } else {
                    // Создаем новую запись
                    $insertInventoryQuery = "
                        INSERT INTO inventory 
                        (warehouse_id, template_id, quantity, product_attributes_hash, created_at, updated_at)
                        VALUES (:warehouse_id, :template_id, :quantity, :attributes_hash, NOW(), NOW())
                    ";
                    
                    $insertInventoryStmt = $pdo->prepare($insertInventoryQuery);
                    $insertInventoryStmt->execute([
                        'warehouse_id' => $transit['warehouse_id'],
                        'template_id' => $templateId,
                        'quantity' => $quantity,
                        'attributes_hash' => $attributesHash
                    ]);
                    
                    $inventoryId = $pdo->lastInsertId();
                    
                    $addedItems[] = [
                        'inventory_id' => $inventoryId,
                        'template_id' => $templateId,
                        'quantity' => $quantity,
                        'previous_quantity' => 0,
                        'new_quantity' => $quantity,
                        'action' => 'created'
                    ];
                }
            }
            
            $pdo->commit();
            
            ApiResponse::success([
                'message' => 'Receiving confirmed successfully',
                'transit_id' => $transitId,
                'status' => 'received',
                'added_items' => $addedItems,
                'warehouse' => $transit['warehouse_name']
            ]);
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        logError('Error confirming receiving: ' . $e->getMessage());
        ApiResponse::serverError('Failed to confirm receiving');
    }
}
?>