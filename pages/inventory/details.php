<?php
/**
 * Детальный просмотр остатков товара на складе
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

// Проверяем авторизацию
requireAuth();

// Проверяем права доступа
if (!hasAccessToSection('inventory')) {
    header('Location: /pages/errors/403.php');
    exit;
}

$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$template_id = (int)($_GET['template_id'] ?? 0);

if (!$warehouse_id || !$template_id) {
    header('Location: /pages/inventory/index.php');
    exit;
}

$pageTitle = 'Детали остатков';
$currentPage = 'inventory';

try {
    $pdo = getDBConnection();
    
    // Получаем информацию об остатках
    $inventory_stmt = $pdo->prepare("
        SELECT 
            i.*,
            w.name as warehouse_name,
            w.address as warehouse_address,
            pt.name as template_name,
            pt.unit as template_unit,
            pt.formula as template_formula,
            pt.description as template_description,
            c.name as company_name
        FROM inventory i
        JOIN warehouses w ON i.warehouse_id = w.id
        JOIN product_templates pt ON i.template_id = pt.id
        JOIN companies c ON w.company_id = c.id
        WHERE i.warehouse_id = ? AND i.template_id = ?
    ");
    $inventory_stmt->execute([$warehouse_id, $template_id]);
    $inventory = $inventory_stmt->fetch();
    
    if (!$inventory) {
        header('Location: /pages/inventory/index.php?error=not_found');
        exit;
    }
    
    // Получаем все товары этого типа на данном складе
    $products_stmt = $pdo->prepare("
        SELECT 
            p.*,
            u.first_name,
            u.last_name
        FROM products p
        JOIN users u ON p.created_by = u.id
        WHERE p.warehouse_id = ? AND p.template_id = ?
        ORDER BY p.arrival_date DESC, p.created_at DESC
    ");
    $products_stmt->execute([$warehouse_id, $template_id]);
    $products = $products_stmt->fetchAll();
    
    // Получаем атрибуты шаблона
    $attributes_stmt = $pdo->prepare("
        SELECT * FROM template_attributes 
        WHERE template_id = ? 
        ORDER BY sort_order, id
    ");
    $attributes_stmt->execute([$template_id]);
    $template_attributes = $attributes_stmt->fetchAll();
    
    // Получаем историю движения товаров (если есть таблица операций)
    $movements = [];
    try {
        $movements_stmt = $pdo->prepare("
            SELECT 
                wo.*,
                u.first_name,
                u.last_name
            FROM warehouse_operations wo
            LEFT JOIN users u ON wo.created_by = u.id
            WHERE wo.warehouse_id = ? AND wo.template_id = ?
            ORDER BY wo.created_at DESC
            LIMIT 10
        ");
        $movements_stmt->execute([$warehouse_id, $template_id]);
        $movements = $movements_stmt->fetchAll();
    } catch (Exception $e) {
        // Таблица операций может не существовать
        $movements = [];
    }
    
} catch (Exception $e) {
    logError('Inventory details error: ' . $e->getMessage());
    $error_message = 'Ошибка при загрузке деталей остатков: ' . $e->getMessage();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Навигация -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="/pages/inventory/index.php">Остатки на складах</a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">
                        <?= htmlspecialchars($inventory['template_name'] ?? 'Детали') ?>
                    </li>
                </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">
                    <i class="bi bi-box-seam"></i>
                    Остатки: <?= htmlspecialchars($inventory['template_name'] ?? '') ?>
                </h1>
                <div class="btn-group" role="group">
                    <a href="/pages/inventory/movement.php?warehouse_id=<?= $warehouse_id ?>&template_id=<?= $template_id ?>" 
                       class="btn btn-primary">
                        <i class="bi bi-arrow-left-right"></i>
                        Движение товара
                    </a>
                    <a href="/pages/inventory/index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Назад к списку
                    </a>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Основная информация -->
                <div class="col-lg-8">
                    <!-- Карточка остатков -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-boxes"></i>
                                Текущие остатки
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="text-center p-3 bg-primary bg-opacity-10 rounded">
                                        <i class="bi bi-box-seam text-primary" style="font-size: 2rem;"></i>
                                        <h4 class="text-primary mt-2 mb-0">
                                            <?= number_format($inventory['quantity'], 3) ?>
                                        </h4>
                                        <small class="text-muted">В наличии</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-center p-3 bg-info bg-opacity-10 rounded">
                                        <i class="bi bi-boxes text-info" style="font-size: 2rem;"></i>
                                        <h4 class="text-info mt-2 mb-0">
                                            <?= htmlspecialchars($inventory['template_unit']) ?>
                                        </h4>
                                        <small class="text-muted">Единица измерения</small>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <strong>Единица измерения:</strong> <?= htmlspecialchars($inventory['template_unit']) ?>
                                <br>
                                <strong>Последнее обновление:</strong> 
                                <?= date('d.m.Y H:i:s', strtotime($inventory['last_updated'])) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Список товаров -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-list-ul"></i>
                                Товары на складе (<?= count($products ?? []) ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($products)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="text-muted mt-3">Товары не найдены</h5>
                                    <p class="text-muted">На данном складе нет товаров этого типа</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>Дата поступления</th>
                                                <th>Транспорт</th>
                                                <th>Характеристики</th>
                                                <th>Объем</th>
                                                <th>Добавил</th>
                                                <th>Действия</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($products as $product): ?>
                                                <?php $attributes = json_decode($product['attributes'], true) ?: []; ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-secondary">#<?= $product['id'] ?></span>
                                                    </td>
                                                    <td><?= date('d.m.Y', strtotime($product['arrival_date'])) ?></td>
                                                    <td>
                                                        <?= $product['transport_number'] ? htmlspecialchars($product['transport_number']) : '-' ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($attributes)): ?>
                                                            <small>
                                                                <?php foreach ($attributes as $key => $value): ?>
                                                                    <span class="badge bg-light text-dark me-1">
                                                                        <?= htmlspecialchars($key) ?>: <?= htmlspecialchars($value) ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($product['calculated_volume']): ?>
                                                            <span class="badge bg-info">
                                                                <?= number_format($product['calculated_volume'], 3) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?= htmlspecialchars($product['first_name'] . ' ' . $product['last_name']) ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <a href="/pages/products/view.php?id=<?= $product['id'] ?>" 
                                                           class="btn btn-sm btn-outline-primary" title="Просмотр">
                                                            <i class="bi bi-eye"></i>
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

                    <!-- История движения -->
                    <?php if (!empty($movements)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history"></i>
                                    Последние операции
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Дата</th>
                                                <th>Операция</th>
                                                <th>Количество</th>
                                                <th>Описание</th>
                                                <th>Пользователь</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($movements as $movement): ?>
                                                <tr>
                                                    <td>
                                                        <small><?= date('d.m.Y H:i', strtotime($movement['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?= $movement['operation_type'] == 'in' ? 'bg-success' : 'bg-danger' ?>">
                                                            <?= $movement['operation_type'] == 'in' ? 'Приход' : 'Расход' ?>
                                                        </span>
                                                    </td>
                                                    <td><?= number_format($movement['quantity'], 3) ?></td>
                                                    <td>
                                                        <small><?= htmlspecialchars($movement['description'] ?? '-') ?></small>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?= htmlspecialchars(($movement['first_name'] ?? '') . ' ' . ($movement['last_name'] ?? '')) ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Боковая панель -->
                <div class="col-lg-4">
                    <!-- Информация о складе -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-building"></i>
                                Информация о складе
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6><?= htmlspecialchars($inventory['warehouse_name']) ?></h6>
                            <p class="text-muted mb-2">
                                <strong>Компания:</strong> <?= htmlspecialchars($inventory['company_name']) ?>
                            </p>
                            <?php if ($inventory['warehouse_address']): ?>
                                <p class="text-muted mb-0">
                                    <i class="bi bi-geo-alt"></i>
                                    <?= htmlspecialchars($inventory['warehouse_address']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Информация о товаре -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-tag"></i>
                                Тип товара
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6><?= htmlspecialchars($inventory['template_name']) ?></h6>
                            <?php if ($inventory['template_description']): ?>
                                <p class="text-muted"><?= htmlspecialchars($inventory['template_description']) ?></p>
                            <?php endif; ?>
                            
                            <p class="mb-2">
                                <strong>Единица измерения:</strong> <?= htmlspecialchars($inventory['template_unit']) ?>
                            </p>
                            
                            <?php if ($inventory['template_formula']): ?>
                                <p class="mb-2">
                                    <strong>Формула расчета:</strong>
                                    <br>
                                    <code><?= htmlspecialchars($inventory['template_formula']) ?></code>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($template_attributes)): ?>
                                <div class="mt-3">
                                    <strong>Характеристики:</strong>
                                    <ul class="list-unstyled mt-2">
                                        <?php foreach ($template_attributes as $attr): ?>
                                            <li class="mb-1">
                                                <small>
                                                    <span class="badge bg-light text-dark">
                                                        <?= htmlspecialchars($attr['name']) ?>
                                                    </span>
                                                    <span class="text-muted">
                                                        (<?= htmlspecialchars($attr['data_type']) ?>)
                                                        <?= $attr['is_required'] ? ' *' : '' ?>
                                                        <?= $attr['in_formula'] ? ' ⚡' : '' ?>
                                                    </span>
                                                </small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <small class="text-muted">
                                        * - обязательное поле<br>
                                        ⚡ - используется в формуле
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Быстрые действия -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-lightning"></i>
                                Быстрые действия
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="/pages/products/create.php?warehouse_id=<?= $warehouse_id ?>&template_id=<?= $template_id ?>" 
                                   class="btn btn-success">
                                    <i class="bi bi-plus-circle"></i>
                                    Добавить товар
                                </a>
                                <a href="/pages/inventory/movement.php?warehouse_id=<?= $warehouse_id ?>&template_id=<?= $template_id ?>" 
                                   class="btn btn-primary">
                                    <i class="bi bi-arrow-left-right"></i>
                                    Движение товара
                                </a>
                                <a href="/pages/inventory/reports.php?warehouse_id=<?= $warehouse_id ?>&template_id=<?= $template_id ?>" 
                                   class="btn btn-outline-primary">
                                    <i class="bi bi-graph-up"></i>
                                    Отчеты
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>