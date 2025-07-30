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
$pageTitle = 'Выбор склада для реализации';

try {
    $pdo = getDBConnection();
    
    // Получаем список доступных складов
    $warehousesQuery = "
        SELECT w.id, w.name, w.address, c.name as company_name,
               COUNT(i.id) as inventory_count,
               COALESCE(SUM(i.quantity), 0) as total_quantity
        FROM warehouses w 
        JOIN companies c ON w.company_id = c.id 
        LEFT JOIN inventory i ON w.id = i.warehouse_id AND i.quantity > 0
        WHERE w.status = 1
    ";
    
    // Ограичиваем склады для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $warehousesQuery .= " AND w.id = " . $currentUser['warehouse_id'];
    }
    
    $warehousesQuery .= " GROUP BY w.id, w.name, w.address, c.name ORDER BY c.name, w.name";
    
    $stmt = $pdo->prepare($warehousesQuery);
    $stmt->execute();
    $warehouses = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError('Warehouse select error: ' . $e->getMessage());
    $warehouses = [];
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Назад к списку
                </a>
            </div>

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-4">
                                <i class="bi bi-building"></i> Выберите склад для реализации товара
                            </h5>
                            
                            <?php if (empty($warehouses)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-exclamation-triangle display-1 text-warning"></i>
                                    <h4 class="text-muted">Нет доступных складов</h4>
                                    <p class="text-muted">
                                        У вас нет доступа к складам или на складах нет товаров для реализации
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100 warehouse-card">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <i class="bi bi-building text-primary"></i>
                                                        <?= htmlspecialchars($warehouse['name']) ?>
                                                    </h6>
                                                    <p class="card-text">
                                                        <strong>Компания:</strong> <?= htmlspecialchars($warehouse['company_name']) ?><br>
                                                        <?php if (!empty($warehouse['address'])): ?>
                                                            <strong>Адрес:</strong> <?= htmlspecialchars($warehouse['address']) ?><br>
                                                        <?php endif; ?>
                                                        <strong>Позиций в наличии:</strong> <?= number_format($warehouse['inventory_count']) ?>
                                                    </p>
                                                    
                                                    <?php if ($warehouse['inventory_count'] > 0): ?>
                                                        <a href="products_select.php?warehouse_id=<?= $warehouse['id'] ?>" 
                                                           class="btn btn-primary">
                                                            <i class="bi bi-box-seam"></i> Выбрать товары
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-secondary" disabled>
                                                            <i class="bi bi-x-circle"></i> Нет товаров
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.warehouse-card {
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
}

.warehouse-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
</style>

<?php include '../../includes/footer.php'; ?>