<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Проверяем авторизацию и права доступа
if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

// Проверяем права доступа к разделу реализации
if (!hasAccessToSection('sales')) {
    redirect('/pages/errors/403.php');
}

$currentUser = getCurrentUser();
$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$saleId) {
    redirect('index.php');
}

$pageTitle = 'Просмотр реализации';

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о реализации
    $saleQuery = "
        SELECT 
            s.*,
            pt.name as template_name,
            pt.unit,
            w.name as warehouse_name,
            w.address as warehouse_address,
            c.name as company_name,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            u.username as created_by_username
        FROM sales s
        JOIN product_templates pt ON s.template_id = pt.id
        JOIN warehouses w ON s.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        JOIN users u ON s.created_by = u.id
        WHERE s.id = ?
    ";
    
    // Ограичиваем доступ для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $saleQuery .= " AND s.warehouse_id = " . $currentUser['warehouse_id'];
    }
    
    $stmt = $pdo->prepare($saleQuery);
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    
    if (!$sale) {
        redirect('index.php');
    }
    
    // Получаем атрибуты товара
    $attributesQuery = "
        SELECT 
            ta.name,
            ta.variable,
            ta.data_type,
            ta.options,
            ta.unit,
            p.attributes as product_attributes
        FROM template_attributes ta
        LEFT JOIN products p ON p.template_id = ta.template_id 
            AND SHA2(p.attributes, 256) = ?
        WHERE ta.template_id = ?
        ORDER BY ta.sort_order
    ";
    
    $stmt = $pdo->prepare($attributesQuery);
    $stmt->execute([$sale['product_attributes_hash'], $sale['template_id']]);
    $templateAttributes = $stmt->fetchAll();
    
    $productAttributes = [];
    if (!empty($templateAttributes) && !empty($templateAttributes[0]['product_attributes'])) {
        $productAttributes = json_decode($templateAttributes[0]['product_attributes'], true) ?: [];
    }
    
} catch (Exception $e) {
    logError('Sale view error: ' . $e->getMessage());
    redirect('index.php');
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1><?= htmlspecialchars($pageTitle) ?> #<?= $sale['id'] ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Реализация</a></li>
                            <li class="breadcrumb-item active">Просмотр #<?= $sale['id'] ?></li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <?php if (hasAccessToSection('sales', 'edit')): ?>
                        <a href="edit.php?id=<?= $sale['id'] ?>" class="btn btn-outline-primary">
                            <i class="bi bi-pencil"></i> Редактировать
                        </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Назад к списку
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8">
                    <!-- Основная информация о реализации -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-receipt"></i> Информация о реализации
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Основные данные</h6>
                                    <table class="table table-borderless">
                                        <tr>
                                            <td><strong>Дата реализации:</strong></td>
                                            <td><?= date('d.m.Y', strtotime($sale['sale_date'])) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Покупатель:</strong></td>
                                            <td><?= htmlspecialchars($sale['buyer']) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Количество:</strong></td>
                                            <td><?= number_format($sale['quantity'], 3) ?> <?= htmlspecialchars($sale['unit']) ?></td>
                                        </tr>
                                        <?php if ($sale['exchange_rate'] != 1): ?>
                                        <tr>
                                            <td><strong>Курс валюты:</strong></td>
                                            <td><?= number_format($sale['exchange_rate'], 4) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Финансовые данные</h6>
                                    <table class="table table-borderless">
                                        <?php if ($sale['price_cashless'] > 0): ?>
                                        <tr>
                                            <td><strong>Цена безналичная:</strong></td>
                                            <td>$<?= number_format($sale['price_cashless'], 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($sale['price_cash'] > 0): ?>
                                        <tr>
                                            <td><strong>Цена наличная:</strong></td>
                                            <td>$<?= number_format($sale['price_cash'], 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr class="table-success">
                                            <td><strong>ИТОГО:</strong></td>
                                            <td><strong>$<?= number_format($sale['total_amount'], 2) ?></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Информация о товаре -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-box-seam"></i> Информация о товаре
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6><?= htmlspecialchars($sale['template_name']) ?></h6>
                            
                            <?php if (!empty($templateAttributes)): ?>
                                <hr>
                                <h6>Характеристики:</h6>
                                <div class="row">
                                    <?php foreach ($templateAttributes as $attr): ?>
                                        <?php 
                                        $value = $productAttributes[$attr['variable']] ?? 'Не указано';
                                        if ($attr['data_type'] === 'select' && !empty($attr['options'])) {
                                            $options = explode(',', $attr['options']);
                                            $value = trim($options[0] ?? $value);
                                        }
                                        ?>
                                        <div class="col-md-6 mb-2">
                                            <strong><?= htmlspecialchars($attr['name']) ?>:</strong>
                                            <?= htmlspecialchars($value) ?>
                                            <?php if (!empty($attr['unit'])): ?>
                                                <?= htmlspecialchars($attr['unit']) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Информация о складе -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-building"></i> Склад
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6><?= htmlspecialchars($sale['warehouse_name']) ?></h6>
                            <p class="text-muted mb-2"><?= htmlspecialchars($sale['company_name']) ?></p>
                            <?php if (!empty($sale['warehouse_address'])): ?>
                                <p class="mb-0">
                                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($sale['warehouse_address']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Информация о создании -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle"></i> Дополнительная информация
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-2">
                                <strong>Создал:</strong><br>
                                <?= htmlspecialchars($sale['created_by_name']) ?>
                                <small class="text-muted">(<?= htmlspecialchars($sale['created_by_username']) ?>)</small>
                            </p>
                            <p class="mb-2">
                                <strong>Дата создания:</strong><br>
                                <?= date('d.m.Y H:i:s', strtotime($sale['created_at'])) ?>
                            </p>
                            <?php if ($sale['updated_at'] && $sale['updated_at'] !== $sale['created_at']): ?>
                                <p class="mb-0">
                                    <strong>Последнее изменение:</strong><br>
                                    <?= date('d.m.Y H:i:s', strtotime($sale['updated_at'])) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Статус -->
                    <div class="card mt-4">
                        <div class="card-body text-center">
                            <span class="badge bg-success fs-6">
                                <i class="bi bi-check-circle"></i> Реализация завершена
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>