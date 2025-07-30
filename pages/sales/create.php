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
$inventoryId = isset($_GET['inventory_id']) ? (int)$_GET['inventory_id'] : 0;

if (!$warehouseId || !$inventoryId) {
    redirect('warehouse_select.php');
}

$pageTitle = 'Создание реализации';
$errors = [];
$success = false;

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о товаре и складе
    $inventoryQuery = "
        SELECT 
            i.id as inventory_id,
            i.template_id,
            i.product_attributes_hash,
            i.quantity,
            pt.name as template_name,
            pt.unit,
            pt.formula,
            w.id as warehouse_id,
            w.name as warehouse_name,
            c.name as company_name,
            -- Получаем атрибуты товара
            p.attributes as product_attributes
        FROM inventory i
        JOIN product_templates pt ON i.template_id = pt.id
        JOIN warehouses w ON i.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        LEFT JOIN products p ON p.template_id = i.template_id 
            AND SHA2(p.attributes, 256) = i.product_attributes_hash
            AND p.warehouse_id = i.warehouse_id
        WHERE i.id = ? AND i.warehouse_id = ? AND i.quantity > 0
    ";
    
    // Ограичиваем склады для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $inventoryQuery .= " AND w.id = " . $currentUser['warehouse_id'];
    }
    
    $stmt = $pdo->prepare($inventoryQuery);
    $stmt->execute([$inventoryId, $warehouseId]);
    $inventory = $stmt->fetch();
    
    if (!$inventory) {
        redirect('warehouse_select.php');
    }
    
    // Получаем атрибуты шаблона для отображения
    $attributesQuery = "
        SELECT name, variable, data_type, options, unit, is_required
        FROM template_attributes 
        WHERE template_id = ? 
        ORDER BY sort_order
    ";
    $stmt = $pdo->prepare($attributesQuery);
    $stmt->execute([$inventory['template_id']]);
    $templateAttributes = $stmt->fetchAll();
    
    // Парсим атрибуты товара
    $productAttributes = json_decode($inventory['product_attributes'] ?? '{}', true) ?: [];
    
} catch (Exception $e) {
    logError('Create sale page error: ' . $e->getMessage());
    redirect('warehouse_select.php');
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saleDate = trim($_POST['sale_date'] ?? '');
    $buyer = trim($_POST['buyer'] ?? '');
    $quantity = (float)($_POST['quantity'] ?? 0);
    $priceCashless = (float)($_POST['price_cashless'] ?? 0);
    $priceCash = (float)($_POST['price_cash'] ?? 0);
    $exchangeRate = (float)($_POST['exchange_rate'] ?? 1);
    
    // Валидация
    if (empty($saleDate)) {
        $errors[] = 'Дата реализации обязательна для заполнения';
    }
    
    if (empty($buyer)) {
        $errors[] = 'Покупатель обязателен для заполнения';
    }
    
    if ($quantity <= 0) {
        $errors[] = 'Количество должно быть больше 0';
    }
    
    if ($quantity > $inventory['quantity']) {
        $errors[] = 'Количество не может превышать остаток на складе (' . number_format($inventory['quantity'], 3) . ' ' . $inventory['unit'] . ')';
    }
    
    if ($priceCashless < 0 || $priceCash < 0) {
        $errors[] = 'Цены не могут быть отрицательными';
    }
    
    if ($priceCashless == 0 && $priceCash == 0) {
        $errors[] = 'Укажите хотя бы одну цену (безналичная или наличная)';
    }
    
    if ($exchangeRate <= 0) {
        $exchangeRate = 1;
    }
    
    // Если нет ошибок, создаем реализацию
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $totalAmount = $priceCashless + $priceCash;
            
            // Создаем запись реализации
            $insertQuery = "
                INSERT INTO sales (
                    warehouse_id, template_id, product_attributes_hash,
                    sale_date, buyer, quantity, 
                    price_cashless, price_cash, exchange_rate, total_amount,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $pdo->prepare($insertQuery);
            $stmt->execute([
                $inventory['warehouse_id'],
                $inventory['template_id'],
                $inventory['product_attributes_hash'],
                $saleDate,
                $buyer,
                $quantity,
                $priceCashless,
                $priceCash,
                $exchangeRate,
                $totalAmount,
                $currentUser['id']
            ]);
            
            $saleId = $pdo->lastInsertId();
            
            // Обновляем остатки
            $updateInventoryQuery = "
                UPDATE inventory 
                SET quantity = quantity - ?, last_updated = CURRENT_TIMESTAMP 
                WHERE id = ?
            ";
            $stmt = $pdo->prepare($updateInventoryQuery);
            $stmt->execute([$quantity, $inventoryId]);
            
            // Логируем операцию в warehouse_operations
            // Находим любой продукт с данным template_id для логирования
            $productQuery = "SELECT id FROM products WHERE template_id = ? LIMIT 1";
            $stmt = $pdo->prepare($productQuery);
            $stmt->execute([$inventory['template_id']]);
            $product = $stmt->fetch();
            
            if ($product) {
                $quantityBefore = $inventory['quantity'];
                $quantityAfter = $quantityBefore - $quantity;
                
                $logQuery = "
                    INSERT INTO warehouse_operations 
                    (warehouse_id, product_id, operation_type, quantity_change, quantity_before, quantity_after, notes, created_by) 
                    VALUES (?, ?, 'sale', ?, ?, ?, ?, ?)
                ";
                $stmt = $pdo->prepare($logQuery);
                $stmt->execute([
                    $inventory['warehouse_id'],
                    $product['id'],
                    -$quantity,
                    $quantityBefore,
                    $quantityAfter,
                    "Реализация товара покупателю: " . $buyer,
                    $currentUser['id']
                ]);
            }
            
            $pdo->commit();
            $success = true;
            
            // Перенаправляем на страницу просмотра созданной реализации
            redirect("view.php?id=$saleId");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            logError('Create sale error: ' . $e->getMessage());
            $errors[] = 'Ошибка при создании реализации. Попробуйте еще раз.';
        }
    }
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
                            <li class="breadcrumb-item"><a href="products_select.php?warehouse_id=<?= $warehouseId ?>">Выбор товара</a></li>
                            <li class="breadcrumb-item active">Создание реализации</li>
                        </ol>
                    </nav>
                </div>
                <a href="products_select.php?warehouse_id=<?= $warehouseId ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Назад к товарам
                </a>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <h6>Исправьте следующие ошибки:</h6>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <!-- Форма создания реализации -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-plus-circle"></i> Данные реализации
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="saleForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="sale_date" class="form-label">Дата реализации <span class="text-danger">*</span></label>
                                            <input type="date" name="sale_date" id="sale_date" class="form-control" 
                                                   value="<?= $_POST['sale_date'] ?? date('Y-m-d') ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="buyer" class="form-label">Покупатель <span class="text-danger">*</span></label>
                                            <input type="text" name="buyer" id="buyer" class="form-control" 
                                                   value="<?= htmlspecialchars($_POST['buyer'] ?? '') ?>" 
                                                   placeholder="Введите название покупателя" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="quantity" class="form-label">
                                                Количество <span class="text-danger">*</span>
                                                <small class="text-muted">(макс: <?= number_format($inventory['quantity'], 3) ?> <?= htmlspecialchars($inventory['unit']) ?>)</small>
                                            </label>
                                            <div class="input-group">
                                                <input type="number" name="quantity" id="quantity" class="form-control" 
                                                       step="0.001" min="0.001" max="<?= $inventory['quantity'] ?>"
                                                       value="<?= $_POST['quantity'] ?? '' ?>" required>
                                                <span class="input-group-text"><?= htmlspecialchars($inventory['unit']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="exchange_rate" class="form-label">
                                                Курс валюты <small class="text-muted">(справочно)</small>
                                            </label>
                                            <input type="number" name="exchange_rate" id="exchange_rate" class="form-control" 
                                                   step="0.0001" min="0.0001" 
                                                   value="<?= $_POST['exchange_rate'] ?? '1' ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="price_cashless" class="form-label">Цена безналичная ($)</label>
                                            <input type="number" name="price_cashless" id="price_cashless" class="form-control" 
                                                   step="0.01" min="0" 
                                                   value="<?= $_POST['price_cashless'] ?? '' ?>"
                                                   onchange="calculateTotal()">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="price_cash" class="form-label">Цена наличная ($)</label>
                                            <input type="number" name="price_cash" id="price_cash" class="form-control" 
                                                   step="0.01" min="0" 
                                                   value="<?= $_POST['price_cash'] ?? '' ?>"
                                                   onchange="calculateTotal()">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">ИТОГО к оплате</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="text" id="total_amount" class="form-control form-control-lg" 
                                                       readonly style="font-weight: bold; font-size: 1.2em;">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="products_select.php?warehouse_id=<?= $warehouseId ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle"></i> Отмена
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Создать реализацию
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Информация о товаре -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-box-seam"></i> Информация о товаре
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6><?= htmlspecialchars($inventory['template_name']) ?></h6>
                            
                            <hr>
                            
                            <p class="mb-2">
                                <strong>Склад:</strong><br>
                                <small class="text-muted"><?= htmlspecialchars($inventory['company_name']) ?></small><br>
                                <?= htmlspecialchars($inventory['warehouse_name']) ?>
                            </p>
                            
                            <p class="mb-2">
                                <strong>Остаток:</strong><br>
                                <span class="badge bg-success fs-6">
                                    <?= number_format($inventory['quantity'], 3) ?> <?= htmlspecialchars($inventory['unit']) ?>
                                </span>
                            </p>
                            
                            <?php if (!empty($templateAttributes)): ?>
                                <hr>
                                <h6>Характеристики товара:</h6>
                                <?php foreach ($templateAttributes as $attr): ?>
                                    <?php 
                                    $value = $productAttributes[$attr['variable']] ?? 'Не указано';
                                    if ($attr['data_type'] === 'select' && !empty($attr['options'])) {
                                        $options = explode(',', $attr['options']);
                                        $value = trim($options[0] ?? $value);
                                    }
                                    ?>
                                    <p class="mb-1">
                                        <strong><?= htmlspecialchars($attr['name']) ?>:</strong>
                                        <?= htmlspecialchars($value) ?>
                                        <?php if (!empty($attr['unit'])): ?>
                                            <?= htmlspecialchars($attr['unit']) ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function calculateTotal() {
    const priceCashless = parseFloat(document.getElementById('price_cashless').value) || 0;
    const priceCash = parseFloat(document.getElementById('price_cash').value) || 0;
    const total = priceCashless + priceCash;
    
    document.getElementById('total_amount').value = total.toFixed(2);
}

// Вычисляем итого при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    calculateTotal();
});
</script>

<?php include '../../includes/footer.php'; ?>