<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Проверяем авторизацию и права доступа
if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

// Проверяем права доступа к разделу реализации
if (!hasAccessToSection('sales', 'edit')) {
    redirect('/pages/errors/403.php');
}

$currentUser = getCurrentUser();
$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$saleId) {
    redirect('index.php');
}

$pageTitle = 'Редактирование реализации';
$errors = [];
$success = false;

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о реализации
    $saleQuery = "
        SELECT 
            s.*,
            pt.name as template_name,
            pt.unit,
            w.name as warehouse_name,
            c.name as company_name
        FROM sales s
        JOIN product_templates pt ON s.template_id = pt.id
        JOIN warehouses w ON s.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
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
    
    // Получаем информацию об остатках для этого товара
    $inventoryQuery = "
        SELECT quantity 
        FROM inventory 
        WHERE warehouse_id = ? AND template_id = ? AND product_attributes_hash = ?
    ";
    $stmt = $pdo->prepare($inventoryQuery);
    $stmt->execute([$sale['warehouse_id'], $sale['template_id'], $sale['product_attributes_hash']]);
    $inventory = $stmt->fetch();
    
    // Доступное количество = текущий остаток + количество из этой реализации
    $availableQuantity = ($inventory['quantity'] ?? 0) + $sale['quantity'];
    
} catch (Exception $e) {
    logError('Sale edit page error: ' . $e->getMessage());
    redirect('index.php');
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
    
    if ($quantity > $availableQuantity) {
        $errors[] = 'Количество не может превышать доступный остаток (' . number_format($availableQuantity, 3) . ' ' . $sale['unit'] . ')';
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
    
    // Если нет ошибок, обновляем реализацию
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $totalAmount = $priceCashless + $priceCash;
            $quantityDifference = $quantity - $sale['quantity'];
            
            // Обновляем запись реализации
            $updateQuery = "
                UPDATE sales SET
                    sale_date = ?, buyer = ?, quantity = ?, 
                    price_cashless = ?, price_cash = ?, exchange_rate = ?, total_amount = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ";
            
            $stmt = $pdo->prepare($updateQuery);
            $stmt->execute([
                $saleDate,
                $buyer,
                $quantity,
                $priceCashless,
                $priceCash,
                $exchangeRate,
                $totalAmount,
                $saleId
            ]);
            
            // Корректируем остатки если изменилось количество
            if ($quantityDifference != 0) {
                $updateInventoryQuery = "
                    UPDATE inventory 
                    SET quantity = quantity - ?, last_updated = CURRENT_TIMESTAMP 
                    WHERE warehouse_id = ? AND template_id = ? AND product_attributes_hash = ?
                ";
                $stmt = $pdo->prepare($updateInventoryQuery);
                $stmt->execute([
                    $quantityDifference,
                    $sale['warehouse_id'],
                    $sale['template_id'],
                    $sale['product_attributes_hash']
                ]);
                
                // Логируем корректировку в warehouse_operations
                if (abs($quantityDifference) > 0.001) {
                    $productQuery = "SELECT id FROM products WHERE template_id = ? LIMIT 1";
                    $stmt = $pdo->prepare($productQuery);
                    $stmt->execute([$sale['template_id']]);
                    $product = $stmt->fetch();
                    
                    if ($product) {
                        // Получаем текущий остаток до изменения
                        $currentInventoryQuery = "SELECT quantity FROM inventory WHERE warehouse_id = ? AND template_id = ? AND product_attributes_hash = ?";
                        $stmt = $pdo->prepare($currentInventoryQuery);
                        $stmt->execute([$sale['warehouse_id'], $sale['template_id'], $sale['product_attributes_hash']]);
                        $currentInventory = $stmt->fetch();
                        
                        $quantityBefore = $currentInventory['quantity'] + $quantityDifference; // До корректировки
                        $quantityAfter = $currentInventory['quantity']; // После корректировки
                        
                        $logQuery = "
                            INSERT INTO warehouse_operations 
                            (warehouse_id, product_id, operation_type, quantity_change, quantity_before, quantity_after, notes, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ";
                        $operationType = $quantityDifference > 0 ? 'sale' : 'arrival';
                        $notes = "Корректировка реализации #$saleId (изменение количества)";
                        
                        $stmt = $pdo->prepare($logQuery);
                        $stmt->execute([
                            $sale['warehouse_id'],
                            $product['id'],
                            $operationType,
                            -$quantityDifference,
                            $quantityBefore,
                            $quantityAfter,
                            $notes,
                            $currentUser['id']
                        ]);
                    }
                }
            }
            
            $pdo->commit();
            $success = true;
            
            // Перенаправляем на страницу просмотра
            redirect("view.php?id=$saleId");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            logError('Update sale error: ' . $e->getMessage());
            $errors[] = 'Ошибка при обновлении реализации. Попробуйте еще раз.';
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
                    <h1><?= htmlspecialchars($pageTitle) ?> #<?= $sale['id'] ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Реализация</a></li>
                            <li class="breadcrumb-item"><a href="view.php?id=<?= $sale['id'] ?>">Просмотр #<?= $sale['id'] ?></a></li>
                            <li class="breadcrumb-item active">Редактирование</li>
                        </ol>
                    </nav>
                </div>
                <a href="view.php?id=<?= $sale['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Назад к просмотру
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
                    <!-- Форма редактирования реализации -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-pencil"></i> Редактирование данных реализации
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="saleForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="sale_date" class="form-label">Дата реализации <span class="text-danger">*</span></label>
                                            <input type="date" name="sale_date" id="sale_date" class="form-control" 
                                                   value="<?= $_POST['sale_date'] ?? $sale['sale_date'] ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="buyer" class="form-label">Покупатель <span class="text-danger">*</span></label>
                                            <input type="text" name="buyer" id="buyer" class="form-control" 
                                                   value="<?= htmlspecialchars($_POST['buyer'] ?? $sale['buyer']) ?>" 
                                                   placeholder="Введите название покупателя" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="quantity" class="form-label">
                                                Количество <span class="text-danger">*</span>
                                                <small class="text-muted">(макс: <?= number_format($availableQuantity, 3) ?> <?= htmlspecialchars($sale['unit']) ?>)</small>
                                            </label>
                                            <div class="input-group">
                                                <input type="number" name="quantity" id="quantity" class="form-control" 
                                                       step="0.001" min="0.001" max="<?= $availableQuantity ?>"
                                                       value="<?= $_POST['quantity'] ?? $sale['quantity'] ?>" required>
                                                <span class="input-group-text"><?= htmlspecialchars($sale['unit']) ?></span>
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
                                                   value="<?= $_POST['exchange_rate'] ?? $sale['exchange_rate'] ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="price_cashless" class="form-label">Цена безналичная ($)</label>
                                            <input type="number" name="price_cashless" id="price_cashless" class="form-control" 
                                                   step="0.01" min="0" 
                                                   value="<?= $_POST['price_cashless'] ?? $sale['price_cashless'] ?>"
                                                   onchange="calculateTotal()">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="price_cash" class="form-label">Цена наличная ($)</label>
                                            <input type="number" name="price_cash" id="price_cash" class="form-control" 
                                                   step="0.01" min="0" 
                                                   value="<?= $_POST['price_cash'] ?? $sale['price_cash'] ?>"
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
                                    <a href="view.php?id=<?= $sale['id'] ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle"></i> Отмена
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Сохранить изменения
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
                            <h6><?= htmlspecialchars($sale['template_name']) ?></h6>
                            
                            <hr>
                            
                            <p class="mb-2">
                                <strong>Склад:</strong><br>
                                <small class="text-muted"><?= htmlspecialchars($sale['company_name']) ?></small><br>
                                <?= htmlspecialchars($sale['warehouse_name']) ?>
                            </p>
                            
                            <p class="mb-2">
                                <strong>Доступно для реализации:</strong><br>
                                <span class="badge bg-info fs-6">
                                    <?= number_format($availableQuantity, 3) ?> <?= htmlspecialchars($sale['unit']) ?>
                                </span>
                            </p>
                            
                            <div class="alert alert-info">
                                <small>
                                    <i class="bi bi-info-circle"></i>
                                    Доступное количество включает текущий остаток на складе плюс количество из этой реализации.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Предупреждение -->
                    <div class="card mt-4">
                        <div class="card-body">
                            <div class="alert alert-warning mb-0">
                                <h6><i class="bi bi-exclamation-triangle"></i> Внимание!</h6>
                                <small>
                                    При изменении количества товара будут автоматически скорректированы остатки на складе.
                                </small>
                            </div>
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