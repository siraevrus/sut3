<?php
/**
 * Inventory Endpoint
 * Система складского учета (SUT)
 */

$action = $pathParts[1] ?? '';
$id = $pathParts[2] ?? null;

switch ($method) {
    case 'GET':
        if ($action === 'warehouse' && $id) {
            getWarehouseInventory($id);
        } elseif ($action === 'product' && $id) {
            getProductInventory($id);
        } else {
            getInventory();
        }
        break;
        
    default:
        ApiResponse::error('Method not allowed', 405);
}

/**
 * Получение общих остатков
 */
function getInventory() {
    ApiAuth::requireAuth();
    $currentUser = ApiAuth::getCurrentUser();
    
    $params = ApiResponse::getQueryParams([
        'page' => 1,
        'limit' => 20,
        'warehouse_id' => 0,
        'template_id' => 0,
        'manufacturer' => '',
        'search' => ''
    ]);
    
    try {
        $pdo = getDBConnection();
        
        // Строим запрос с учетом прав доступа
        $where = ['i.quantity > 0'];
        $bindings = [];
        
        // Ограничения по роли
        if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
            $where[] = "i.warehouse_id = ?";
            $bindings[] = $currentUser['warehouse_id'];
        }
        
        // Фильтры
        if ($params['warehouse_id'] > 0) {
            $where[] = "i.warehouse_id = ?";
            $bindings[] = $params['warehouse_id'];
        }
        
        if ($params['template_id'] > 0) {
            $where[] = "i.template_id = ?";
            $bindings[] = $params['template_id'];
        }
        
        if (!empty($params['manufacturer'])) {
            $where[] = "JSON_EXTRACT(p.attributes, '$.manufacturer') LIKE ?";
            $bindings[] = '%' . $params['manufacturer'] . '%';
        }
        
        if (!empty($params['search'])) {
            $where[] = "pt.name LIKE ?";
            $bindings[] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Подсчитываем общее количество
        $countQuery = "
            SELECT COUNT(*) 
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            JOIN product_templates pt ON i.template_id = pt.id
            JOIN warehouses w ON i.warehouse_id = w.id
            JOIN companies c ON w.company_id = c.id
            WHERE $whereClause
        ";
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute($bindings);
        $total = $stmt->fetchColumn();
        
        // Получаем данные с пагинацией
        $offset = ($params['page'] - 1) * $params['limit'];
        
        $dataQuery = "
            SELECT 
                i.id,
                i.product_id,
                i.warehouse_id,
                i.template_id,
                i.quantity,
                i.reserved_quantity,
                i.quantity - i.reserved_quantity as available_quantity,
                i.last_updated,
                pt.name as template_name,
                w.name as warehouse_name,
                c.name as company_name,
                p.attributes,
                p.calculated_volume,
                p.arrival_date,
                JSON_EXTRACT(p.attributes, '$.manufacturer') as manufacturer
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            JOIN product_templates pt ON i.template_id = pt.id
            JOIN warehouses w ON i.warehouse_id = w.id
            JOIN companies c ON w.company_id = c.id
            WHERE $whereClause
            ORDER BY i.last_updated DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $pdo->prepare($dataQuery);
        $stmt->execute([...$bindings, $params['limit'], $offset]);
        $inventory = $stmt->fetchAll();
        
        // Форматируем данные
        $formattedInventory = array_map(function($item) {
            return [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'warehouse_id' => $item['warehouse_id'],
                'template_id' => $item['template_id'],
                'template_name' => $item['template_name'],
                'warehouse_name' => $item['warehouse_name'],
                'company_name' => $item['company_name'],
                'quantity' => (float)$item['quantity'],
                'reserved_quantity' => (float)$item['reserved_quantity'],
                'available_quantity' => (float)$item['available_quantity'],
                'calculated_volume' => (float)$item['calculated_volume'],
                'manufacturer' => $item['manufacturer'],
                'attributes' => json_decode($item['attributes'], true),
                'arrival_date' => $item['arrival_date'],
                'last_updated' => ApiResponse::formatDate($item['last_updated'])
            ];
        }, $inventory);
        
        ApiResponse::paginated($formattedInventory, $total, $params['page'], $params['limit']);
        
    } catch (Exception $e) {
        logError('API Get inventory error: ' . $e->getMessage());
        ApiResponse::error('Failed to retrieve inventory', 500);
    }
}

/**
 * Получение остатков по складу
 */
function getWarehouseInventory($warehouseId) {
    ApiAuth::requireAuth();
    $currentUser = ApiAuth::getCurrentUser();
    
    // Проверяем доступ к складу
    if ($currentUser['role'] !== 'admin' && 
        $currentUser['warehouse_id'] && 
        $currentUser['warehouse_id'] != $warehouseId) {
        ApiResponse::forbidden('Access denied to this warehouse');
    }
    
    try {
        $pdo = getDBConnection();
        
        // Проверяем существование склада
        $stmt = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
        $stmt->execute([$warehouseId]);
        $warehouse = $stmt->fetch();
        
        if (!$warehouse) {
            ApiResponse::notFound('Warehouse not found');
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                i.id,
                i.product_id,
                i.quantity,
                i.reserved_quantity,
                i.quantity - i.reserved_quantity as available_quantity,
                pt.name as template_name,
                p.attributes,
                p.calculated_volume,
                JSON_EXTRACT(p.attributes, '$.manufacturer') as manufacturer
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            JOIN product_templates pt ON i.template_id = pt.id
            WHERE i.warehouse_id = ? AND i.quantity > 0
            ORDER BY pt.name
        ");
        $stmt->execute([$warehouseId]);
        $inventory = $stmt->fetchAll();
        
        $formattedInventory = array_map(function($item) {
            return [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'template_name' => $item['template_name'],
                'quantity' => (float)$item['quantity'],
                'reserved_quantity' => (float)$item['reserved_quantity'],
                'available_quantity' => (float)$item['available_quantity'],
                'calculated_volume' => (float)$item['calculated_volume'],
                'manufacturer' => $item['manufacturer'],
                'attributes' => json_decode($item['attributes'], true)
            ];
        }, $inventory);
        
        ApiResponse::success([
            'warehouse' => [
                'id' => $warehouseId,
                'name' => $warehouse['name']
            ],
            'inventory' => $formattedInventory,
            'total_items' => count($formattedInventory)
        ]);
        
    } catch (Exception $e) {
        logError('API Get warehouse inventory error: ' . $e->getMessage(), ['warehouse_id' => $warehouseId]);
        ApiResponse::error('Failed to retrieve warehouse inventory', 500);
    }
}

/**
 * Получение остатков по товару
 */
function getProductInventory($productId) {
    ApiAuth::requireAuth();
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                i.id,
                i.warehouse_id,
                i.quantity,
                i.reserved_quantity,
                i.quantity - i.reserved_quantity as available_quantity,
                w.name as warehouse_name,
                c.name as company_name,
                pt.name as template_name,
                p.attributes
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            JOIN product_templates pt ON i.template_id = pt.id
            JOIN warehouses w ON i.warehouse_id = w.id
            JOIN companies c ON w.company_id = c.id
            WHERE i.product_id = ? AND i.quantity > 0
            ORDER BY w.name
        ");
        $stmt->execute([$productId]);
        $inventory = $stmt->fetchAll();
        
        if (empty($inventory)) {
            ApiResponse::notFound('Product inventory not found');
        }
        
        $formattedInventory = array_map(function($item) {
            return [
                'id' => $item['id'],
                'warehouse_id' => $item['warehouse_id'],
                'warehouse_name' => $item['warehouse_name'],
                'company_name' => $item['company_name'],
                'quantity' => (float)$item['quantity'],
                'reserved_quantity' => (float)$item['reserved_quantity'],
                'available_quantity' => (float)$item['available_quantity']
            ];
        }, $inventory);
        
        $totalQuantity = array_sum(array_column($formattedInventory, 'quantity'));
        $totalAvailable = array_sum(array_column($formattedInventory, 'available_quantity'));
        
        ApiResponse::success([
            'product' => [
                'id' => $productId,
                'template_name' => $inventory[0]['template_name'],
                'attributes' => json_decode($inventory[0]['attributes'], true)
            ],
            'inventory' => $formattedInventory,
            'summary' => [
                'total_quantity' => $totalQuantity,
                'total_available' => $totalAvailable,
                'warehouses_count' => count($formattedInventory)
            ]
        ]);
        
    } catch (Exception $e) {
        logError('API Get product inventory error: ' . $e->getMessage(), ['product_id' => $productId]);
        ApiResponse::error('Failed to retrieve product inventory', 500);
    }
}
?>