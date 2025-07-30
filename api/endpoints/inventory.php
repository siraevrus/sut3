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
        } elseif ($action === 'template' && $id) {
            getProductInventory($id); // Функция переименована для работы с шаблонами
        } else {
            getInventory();
        }
        break;
        
    case 'POST':
        if ($action === 'movement') {
            createInventoryMovement();
        } else {
            ApiResponse::error('Invalid action', 400);
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
        
        // Убираем фильтр по производителю, так как теперь нет прямой связи с продуктами
        
        if (!empty($params['search'])) {
            $where[] = "pt.name LIKE ?";
            $bindings[] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Подсчитываем общее количество
        $countQuery = "
            SELECT COUNT(*) 
            FROM inventory i
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
                i.warehouse_id,
                i.template_id,
                i.quantity,
                i.product_attributes_hash,
                i.last_updated,
                pt.name as template_name,
                pt.unit,
                w.name as warehouse_name,
                c.name as company_name
            FROM inventory i
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
                'warehouse_id' => $item['warehouse_id'],
                'template_id' => $item['template_id'],
                'template_name' => $item['template_name'],
                'warehouse_name' => $item['warehouse_name'],
                'company_name' => $item['company_name'],
                'quantity' => (float)$item['quantity'],
                'unit' => $item['unit'],
                'product_attributes_hash' => $item['product_attributes_hash'],
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
                i.template_id,
                i.quantity,
                i.product_attributes_hash,
                pt.name as template_name,
                pt.unit
            FROM inventory i
            JOIN product_templates pt ON i.template_id = pt.id
            WHERE i.warehouse_id = ? AND i.quantity > 0
            ORDER BY pt.name
        ");
        $stmt->execute([$warehouseId]);
        $inventory = $stmt->fetchAll();
        
        $formattedInventory = array_map(function($item) {
            return [
                'id' => $item['id'],
                'template_id' => $item['template_id'],
                'template_name' => $item['template_name'],
                'quantity' => (float)$item['quantity'],
                'unit' => $item['unit'],
                'product_attributes_hash' => $item['product_attributes_hash']
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
 * Получение остатков по шаблону товара
 */
function getProductInventory($templateId) {
    ApiAuth::requireAuth();
    
    try {
        $pdo = getDBConnection();
        
        // Проверяем существование шаблона
        $stmt = $pdo->prepare("SELECT name, unit FROM product_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        if (!$template) {
            ApiResponse::notFound('Product template not found');
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                i.id,
                i.warehouse_id,
                i.quantity,
                i.product_attributes_hash,
                w.name as warehouse_name,
                c.name as company_name
            FROM inventory i
            JOIN warehouses w ON i.warehouse_id = w.id
            JOIN companies c ON w.company_id = c.id
            WHERE i.template_id = ? AND i.quantity > 0
            ORDER BY w.name
        ");
        $stmt->execute([$templateId]);
        $inventory = $stmt->fetchAll();
        
        if (empty($inventory)) {
            ApiResponse::notFound('Template inventory not found');
        }
        
        $formattedInventory = array_map(function($item) {
            return [
                'id' => $item['id'],
                'warehouse_id' => $item['warehouse_id'],
                'warehouse_name' => $item['warehouse_name'],
                'company_name' => $item['company_name'],
                'quantity' => (float)$item['quantity'],
                'product_attributes_hash' => $item['product_attributes_hash']
            ];
        }, $inventory);
        
        $totalQuantity = array_sum(array_column($formattedInventory, 'quantity'));
        
        ApiResponse::success([
            'template' => [
                'id' => $templateId,
                'name' => $template['name'],
                'unit' => $template['unit']
            ],
            'inventory' => $formattedInventory,
            'summary' => [
                'total_quantity' => $totalQuantity,
                'warehouses_count' => count($formattedInventory)
            ]
        ]);
        
    } catch (Exception $e) {
        logError('API Get template inventory error: ' . $e->getMessage(), ['template_id' => $templateId]);
        ApiResponse::error('Failed to retrieve template inventory', 500);
    }
}

/**
 * Создание движения товара (приход/расход)
 */
function createInventoryMovement() {
    ApiAuth::requireAuth();
    $currentUser = ApiAuth::getCurrentUser();
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Валидация входных данных
    $required = ['warehouse_id', 'template_id', 'operation_type', 'quantity', 'notes'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            ApiResponse::error("Field '$field' is required", 400);
        }
    }
    
    $warehouseId = (int)$input['warehouse_id'];
    $templateId = (int)$input['template_id'];
    $operationType = $input['operation_type'];
    $quantity = (float)$input['quantity'];
    $notes = trim($input['notes']);
    $productAttributesHash = $input['product_attributes_hash'] ?? '';
    
    // Проверяем доступ к складу
    if ($currentUser['role'] !== 'admin' && 
        $currentUser['warehouse_id'] && 
        $currentUser['warehouse_id'] != $warehouseId) {
        ApiResponse::forbidden('Access denied to this warehouse');
    }
    
    // Валидация типа операции
    if (!in_array($operationType, ['income', 'outcome'])) {
        ApiResponse::error('Invalid operation type. Must be "income" or "outcome"', 400);
    }
    
    // Валидация количества
    if ($quantity <= 0) {
        ApiResponse::error('Quantity must be greater than 0', 400);
    }
    
    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();
        
        // Проверяем существование склада и шаблона
        $stmt = $pdo->prepare("SELECT id FROM warehouses WHERE id = ? AND status = 1");
        $stmt->execute([$warehouseId]);
        if (!$stmt->fetch()) {
            ApiResponse::error('Warehouse not found or inactive', 404);
        }
        
        $stmt = $pdo->prepare("SELECT id FROM product_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        if (!$stmt->fetch()) {
            ApiResponse::error('Product template not found', 404);
        }
        
        // Если нет хеша атрибутов, создаем пустой
        if (empty($productAttributesHash)) {
            $productAttributesHash = hash('sha256', '{}');
        }
        
        // Проверяем существующие остатки
        $stmt = $pdo->prepare("
            SELECT id, quantity 
            FROM inventory 
            WHERE warehouse_id = ? AND template_id = ? AND product_attributes_hash = ?
        ");
        $stmt->execute([$warehouseId, $templateId, $productAttributesHash]);
        $existingInventory = $stmt->fetch();
        
        $newQuantity = 0;
        $quantityChange = $operationType === 'income' ? $quantity : -$quantity;
        
        if ($existingInventory) {
            $newQuantity = $existingInventory['quantity'] + $quantityChange;
            
            // Проверяем, что остаток не становится отрицательным
            if ($newQuantity < 0) {
                ApiResponse::error('Insufficient inventory quantity', 400);
            }
            
            // Обновляем существующий остаток
            $stmt = $pdo->prepare("
                UPDATE inventory 
                SET quantity = ?, last_updated = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$newQuantity, $existingInventory['id']]);
            
        } else {
            // Для расхода должны быть остатки
            if ($operationType === 'outcome') {
                ApiResponse::error('No inventory to deduct from', 400);
            }
            
            $newQuantity = $quantity;
            
            // Создаем новую запись остатков
            $stmt = $pdo->prepare("
                INSERT INTO inventory (warehouse_id, template_id, product_attributes_hash, quantity) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$warehouseId, $templateId, $productAttributesHash, $newQuantity]);
        }
        
        // Логируем операцию в warehouse_operations
        // Нужно найти любой продукт с данным template_id для логирования
        $stmt = $pdo->prepare("SELECT id FROM products WHERE template_id = ? LIMIT 1");
        $stmt->execute([$templateId]);
        $product = $stmt->fetch();
        
        if ($product) {
            $stmt = $pdo->prepare("
                INSERT INTO warehouse_operations 
                (warehouse_id, product_id, operation_type, quantity_change, notes, user_id) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $warehouseId, 
                $product['id'], 
                $operationType, 
                $quantityChange, 
                $notes, 
                $currentUser['id']
            ]);
        }
        
        $pdo->commit();
        
        ApiResponse::success([
            'message' => 'Inventory movement created successfully',
            'warehouse_id' => $warehouseId,
            'template_id' => $templateId,
            'operation_type' => $operationType,
            'quantity_change' => $quantityChange,
            'new_quantity' => $newQuantity
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError('API Create inventory movement error: ' . $e->getMessage(), $input);
        ApiResponse::error('Failed to create inventory movement', 500);
    }
}
?>