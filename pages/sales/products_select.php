<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Проверяем авторизацию и права доступа
if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

// Проверяем права доступа к разделу реализации
if (!hasAccessToSection('sales', 'create')) {
    redirect('/pages/errors/403.php');
}

$currentUser = getCurrentUser();
$warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;

if (!$warehouseId) {
    redirect('warehouse_select.php');
}

$pageTitle = 'Выбор товара для реализации';

try {
    $pdo = getDBConnection();
    
    // Проверяем доступ к складу
    $warehouseQuery = "
        SELECT w.id, w.name, c.name as company_name 
        FROM warehouses w 
        JOIN companies c ON w.company_id = c.id 
        WHERE w.id = ? AND w.status = 1
    ";
    
    // Ограичиваем склады для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $warehouseQuery .= " AND w.id = " . $currentUser['warehouse_id'];
    }
    
    $stmt = $pdo->prepare($warehouseQuery);
    $stmt->execute([$warehouseId]);
    $warehouse = $stmt->fetch();
    
    if (!$warehouse) {
        redirect('warehouse_select.php');
    }
    
    // Получаем товары в наличии на складе
    $productsQuery = "
        SELECT 
            i.id as inventory_id,
            i.template_id,
            i.product_attributes_hash,
            i.quantity,
            pt.name as template_name,
            pt.unit,
            pt.formula,
            GROUP_CONCAT(
                CONCAT(ta.name, ': ', 
                    CASE 
                        WHEN ta.data_type = 'select' THEN ta.options
                        ELSE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.attributes, CONCAT('$.', ta.variable))), 'Не указано')
                    END
                ) 
                ORDER BY ta.sort_order 
                SEPARATOR '; '
            ) as attributes_display,
            -- Получаем один продукт для отображения атрибутов
            p.attributes as sample_attributes
        FROM inventory i
        JOIN product_templates pt ON i.template_id = pt.id
        LEFT JOIN template_attributes ta ON pt.id = ta.template_id
        LEFT JOIN products p ON p.template_id = i.template_id 
            AND SHA2(p.attributes, 256) = i.product_attributes_hash
            AND p.warehouse_id = i.warehouse_id
        WHERE i.warehouse_id = ? AND i.quantity > 0
        GROUP BY i.id, i.template_id, i.product_attributes_hash, i.quantity, pt.name, pt.unit, pt.formula, p.attributes
        ORDER BY pt.name
    ";
    
    $stmt = $pdo->prepare($productsQuery);
    $stmt->execute([$warehouseId]);
    $products = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError('Products select error: ' . $e->getMessage());
    $products = [];
    $warehouse = null;
}

if (!$warehouse) {
    redirect('warehouse_select.php');
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1><?= htmlspecialchars($pageTitle) ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Реализация</a></li>
                            <li class="breadcrumb-item"><a href="warehouse_select.php">Выбор склада</a></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($warehouse['name']) ?></li>
                        </ol>
                    </nav>
                </div>
                <a href="warehouse_select.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Назад к складам
                </a>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="bi bi-building"></i> <?= htmlspecialchars($warehouse['name']) ?>
                    </h5>
                    <p class="card-text">
                        <strong>Компания:</strong> <?= htmlspecialchars($warehouse['company_name']) ?>
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <i class="bi bi-box-seam"></i> Товары в наличии
                    </h5>
                    
                    <?php if (empty($products)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h4 class="text-muted">Нет товаров в наличии</h4>
                            <p class="text-muted">На данном складе нет товаров для реализации</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Товар</th>
                                        <th>Характеристики</th>
                                        <th>Остаток</th>
                                        <th>Действие</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($product['template_name']) ?></strong>
                                            </td>
                                            <td>
                                                <?php if (!empty($product['attributes_display'])): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($product['attributes_display']) ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">Стандартный товар</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-success fs-6">
                                                    <?= number_format($product['quantity'], 3) ?> <?= htmlspecialchars($product['unit']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="create.php?warehouse_id=<?= $warehouseId ?>&inventory_id=<?= $product['inventory_id'] ?>" 
                                                   class="btn btn-primary btn-sm">
                                                    <i class="bi bi-plus-circle"></i> Создать реализацию
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>