<?php
/**
 * Движение товаров на складе
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

$pageTitle = 'Движение товаров';
$currentPage = 'inventory';

// Параметры фильтрации
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$template_id = (int)($_GET['template_id'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Начало текущего месяца
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Сегодня
$operation_type = $_GET['operation_type'] ?? '';

// Обработка операций прихода/расхода
$success_message = '';
$error_message = '';

if ($_POST && isset($_POST['action'])) {
    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();
        
        $action = $_POST['action'];
        $operation_warehouse_id = (int)$_POST['warehouse_id'];
        $operation_template_id = (int)$_POST['template_id'];
        $quantity = (float)$_POST['quantity'];
        $description = trim($_POST['description'] ?? '');
        
        if (!$operation_warehouse_id || !$operation_template_id || $quantity <= 0) {
            throw new Exception('Все поля должны быть заполнены корректно');
        }
        
        // Проверяем существование склада и шаблона
        $check_stmt = $pdo->prepare("
            SELECT w.name as warehouse_name, pt.name as template_name
            FROM warehouses w, product_templates pt
            WHERE w.id = ? AND w.status = 1 
            AND pt.id = ? AND pt.status = 1
        ");
        $check_stmt->execute([$operation_warehouse_id, $operation_template_id]);
        $check_result = $check_stmt->fetch();
        
        if (!$check_result) {
            throw new Exception('Склад или тип товара не найден');
        }
        
        // Получаем текущие остатки
        $inventory_stmt = $pdo->prepare("
            SELECT * FROM inventory 
            WHERE warehouse_id = ? AND template_id = ?
        ");
        $inventory_stmt->execute([$operation_warehouse_id, $operation_template_id]);
        $current_inventory = $inventory_stmt->fetch();
        
        if ($action === 'income') {
            // Приход товара
            if ($current_inventory) {
                // Обновляем существующие остатки
                $update_stmt = $pdo->prepare("
                    UPDATE inventory 
                    SET quantity = quantity + ?, 
                        last_updated = CURRENT_TIMESTAMP
                    WHERE warehouse_id = ? AND template_id = ?
                ");
                $update_stmt->execute([$quantity, $operation_warehouse_id, $operation_template_id]);
            } else {
                // Создаем новую запись остатков
                $insert_stmt = $pdo->prepare("
                    INSERT INTO inventory (warehouse_id, template_id, quantity) 
                    VALUES (?, ?, ?)
                ");
                $insert_stmt->execute([$operation_warehouse_id, $operation_template_id, $quantity]);
            }
            
            $success_message = "Приход товара на сумму {$quantity} единиц успешно оформлен";
            
        } elseif ($action === 'outcome') {
            // Расход товара
            if (!$current_inventory) {
                throw new Exception('На складе нет остатков данного товара');
            }
            
            if ($quantity > $current_inventory['quantity']) {
                throw new Exception("Недостаточно товара на складе. Доступно: {$current_inventory['quantity']}");
            }
            
            // Списываем товар
            $update_stmt = $pdo->prepare("
                UPDATE inventory 
                SET quantity = quantity - ?, 
                    last_updated = CURRENT_TIMESTAMP
                WHERE warehouse_id = ? AND template_id = ?
            ");
            $update_stmt->execute([$quantity, $operation_warehouse_id, $operation_template_id]);
            
            $success_message = "Расход товара на сумму {$quantity} единиц успешно оформлен";
        }
        
        // Логируем операцию (адаптируем под существующую структуру таблицы)
        try {
            // Получаем текущие остатки для логирования
            $current_inventory_after = $pdo->prepare("
                SELECT quantity FROM inventory 
                WHERE warehouse_id = ? AND template_id = ?
            ");
            $current_inventory_after->execute([$operation_warehouse_id, $operation_template_id]);
            $quantity_after = $current_inventory_after->fetchColumn() ?: 0;
            
            // Вычисляем quantity_before
            $quantity_before = $quantity_after;
            if ($action === 'income') {
                $quantity_before = $quantity_after - $quantity;
            } elseif ($action === 'outcome') {
                $quantity_before = $quantity_after + $quantity;
            }
            
            // Адаптируем тип операции под существующую схему
            $operation_type_map = [
                'income' => 'arrival',
                'outcome' => 'sale',
                'reserve' => 'adjustment',
                'unreserve' => 'adjustment'
            ];
            $mapped_operation_type = $operation_type_map[$action] ?? 'adjustment';
            
            // Для существующей таблицы нужен product_id, но мы работаем с агрегированными остатками
            // Возьмем любой товар этого типа на складе или создадим виртуальную запись
            $product_id_stmt = $pdo->prepare("
                SELECT id FROM products 
                WHERE warehouse_id = ? AND template_id = ? 
                LIMIT 1
            ");
            $product_id_stmt->execute([$operation_warehouse_id, $operation_template_id]);
            $product_id = $product_id_stmt->fetchColumn() ?: 0;
            
            if ($product_id > 0) {
                $quantity_change = ($action === 'income') ? $quantity : -$quantity;
                
                $log_stmt = $pdo->prepare("
                    INSERT INTO warehouse_operations 
                    (operation_type, product_id, warehouse_id, quantity_change, quantity_before, quantity_after, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $log_stmt->execute([
                    $mapped_operation_type,
                    $product_id,
                    $operation_warehouse_id, 
                    $quantity_change,
                    $quantity_before,
                    $quantity_after,
                    $description . " (Операция: $action)",
                    $_SESSION['user_id']
                ]);
            }
        } catch (Exception $e) {
            // Логирование не критично, продолжаем работу
            logError('Warehouse operations logging error: ' . $e->getMessage());
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError('Inventory movement error: ' . $e->getMessage());
        $error_message = $e->getMessage();
    }
}

try {
    $pdo = getDBConnection();
    
    // Получаем список складов
    $warehouses_stmt = $pdo->query("
        SELECT id, name 
        FROM warehouses 
        WHERE status = 1 
        ORDER BY name
    ");
    $warehouses = $warehouses_stmt->fetchAll();
    
    // Получаем список шаблонов
    $templates_stmt = $pdo->query("
        SELECT id, name, unit
        FROM product_templates 
        WHERE status = 1 
        ORDER BY name
    ");
    $templates = $templates_stmt->fetchAll();
    
    // Получаем историю операций
    $where_conditions = ['1=1'];
    $params = [];
    
    if ($warehouse_id) {
        $where_conditions[] = 'i.warehouse_id = ?';
        $params[] = $warehouse_id;
    }
    
    if ($template_id) {
        $where_conditions[] = 'i.template_id = ?';
        $params[] = $template_id;
    }
    
    if ($date_from) {
        $where_conditions[] = 'DATE(i.last_updated) >= ?';
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_conditions[] = 'DATE(i.last_updated) <= ?';
        $params[] = $date_to;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Получаем текущие остатки с фильтрацией
    $inventory_sql = "
        SELECT 
            i.*,
            w.name as warehouse_name,
            pt.name as template_name,
            pt.unit as template_unit,
            c.name as company_name
        FROM inventory i
        JOIN warehouses w ON i.warehouse_id = w.id
        JOIN product_templates pt ON i.template_id = pt.id
        JOIN companies c ON w.company_id = c.id
        WHERE $where_clause
        ORDER BY i.last_updated DESC
        LIMIT 50
    ";
    
    $inventory_stmt = $pdo->prepare($inventory_sql);
    $inventory_stmt->execute($params);
    $inventory_items = $inventory_stmt->fetchAll();
    
    // Получаем операции (адаптировано под существующую структуру)
    $operations = [];
    try {
        $operations_sql = "
            SELECT 
                wo.*,
                w.name as warehouse_name,
                pt.name as template_name,
                pt.unit as template_unit,
                u.first_name,
                u.last_name,
                p.template_id
            FROM warehouse_operations wo
            JOIN warehouses w ON wo.warehouse_id = w.id
            JOIN products p ON wo.product_id = p.id
            JOIN product_templates pt ON p.template_id = pt.id
            LEFT JOIN users u ON wo.created_by = u.id
            WHERE 1=1
        ";
        
        $operations_params = [];
        if ($warehouse_id) {
            $operations_sql .= ' AND wo.warehouse_id = ?';
            $operations_params[] = $warehouse_id;
        }
        
        if ($template_id) {
            $operations_sql .= ' AND p.template_id = ?';
            $operations_params[] = $template_id;
        }
        
        if ($date_from) {
            $operations_sql .= ' AND DATE(wo.created_at) >= ?';
            $operations_params[] = $date_from;
        }
        
        if ($date_to) {
            $operations_sql .= ' AND DATE(wo.created_at) <= ?';
            $operations_params[] = $date_to;
        }
        
        if ($operation_type) {
            // Маппинг типов операций
            $operation_type_reverse_map = [
                'income' => 'arrival',
                'outcome' => 'sale',
                'reserve' => 'adjustment',
                'unreserve' => 'adjustment'
            ];
            $mapped_type = $operation_type_reverse_map[$operation_type] ?? $operation_type;
            $operations_sql .= ' AND wo.operation_type = ?';
            $operations_params[] = $mapped_type;
        }
        
        $operations_sql .= ' ORDER BY wo.created_at DESC LIMIT 100';
        
        $operations_stmt = $pdo->prepare($operations_sql);
        $operations_stmt->execute($operations_params);
        $operations = $operations_stmt->fetchAll();
    } catch (Exception $e) {
        // Логирование ошибки
        logError('Operations query error: ' . $e->getMessage());
        $operations = [];
    }
    
} catch (Exception $e) {
    logError('Inventory movement page error: ' . $e->getMessage());
    $error_message = 'Ошибка при загрузке данных: ' . $e->getMessage();
    $inventory_items = [];
    $operations = [];
    $warehouses = [];
    $templates = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">
                    <i class="bi bi-arrow-left-right"></i>
                    Движение товаров
                </h1>
                <div class="btn-group" role="group">
                    <a href="/pages/inventory/index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        К остаткам
                    </a>
                    <a href="/pages/inventory/reports.php" class="btn btn-outline-primary">
                        <i class="bi bi-graph-up"></i>
                        Отчеты
                    </a>
                </div>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle"></i>
                    <?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Форма операций -->
                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-plus-circle"></i>
                                Новая операция
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="warehouse_id" class="form-label">Склад *</label>
                                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                                        <option value="">Выберите склад</option>
                                        <?php foreach ($warehouses as $warehouse): ?>
                                            <option value="<?= $warehouse['id'] ?>" 
                                                    <?= $warehouse_id == $warehouse['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($warehouse['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="template_id" class="form-label">Тип товара *</label>
                                    <select class="form-select" id="template_id" name="template_id" required>
                                        <option value="">Выберите тип товара</option>
                                        <?php foreach ($templates as $template): ?>
                                            <option value="<?= $template['id'] ?>" 
                                                    data-unit="<?= htmlspecialchars($template['unit']) ?>"
                                                    <?= $template_id == $template['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($template['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="action" class="form-label">Тип операции *</label>
                                    <select class="form-select" id="action" name="action" required>
                                        <option value="">Выберите операцию</option>
                                        <option value="income">Приход товара</option>
                                        <option value="outcome">Расход товара</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="quantity" class="form-label">Количество *</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="quantity" name="quantity" 
                                               step="0.001" min="0.001" required>
                                        <span class="input-group-text" id="unit-display">ед.</span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Описание</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" 
                                              placeholder="Дополнительная информация об операции"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-check-circle"></i>
                                    Выполнить операцию
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Фильтры и данные -->
                <div class="col-lg-8">
                    <!-- Фильтры -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label for="filter_warehouse_id" class="form-label">Склад</label>
                                    <select class="form-select" id="filter_warehouse_id" name="warehouse_id">
                                        <option value="">Все склады</option>
                                        <?php foreach ($warehouses as $warehouse): ?>
                                            <option value="<?= $warehouse['id'] ?>" 
                                                    <?= $warehouse_id == $warehouse['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($warehouse['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="filter_template_id" class="form-label">Тип товара</label>
                                    <select class="form-select" id="filter_template_id" name="template_id">
                                        <option value="">Все типы</option>
                                        <?php foreach ($templates as $template): ?>
                                            <option value="<?= $template['id'] ?>" 
                                                    <?= $template_id == $template['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($template['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="date_from" class="form-label">С даты</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" 
                                           value="<?= htmlspecialchars($date_from) ?>">
                                </div>
                                <div class="col-md-2">
                                    <label for="date_to" class="form-label">По дату</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" 
                                           value="<?= htmlspecialchars($date_to) ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search"></i>
                                        Найти
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Текущие остатки -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-boxes"></i>
                                Текущие остатки
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($inventory_items)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="text-muted mt-3">Остатки не найдены</h5>
                                    <p class="text-muted">Попробуйте изменить параметры фильтра</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Склад</th>
                                                <th>Товар</th>
                                                <th>Количество</th>
                                                <th>Обновлено</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($inventory_items as $item): ?>
                                                <tr>
                                                    <td>
                                                        <small><?= htmlspecialchars($item['warehouse_name']) ?></small>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?= htmlspecialchars($item['template_name']) ?>
                                                            <span class="text-muted">(<?= htmlspecialchars($item['template_unit']) ?>)</span>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?= $item['quantity'] > 0 ? 'bg-success' : 'bg-danger' ?>">
                                                            <?= number_format($item['quantity'], 3) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted">
                                                            <?= date('d.m H:i', strtotime($item['last_updated'])) ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- История операций -->
                    <?php if (!empty($operations)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history"></i>
                                    История операций
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Дата</th>
                                                <th>Склад</th>
                                                <th>Товар</th>
                                                <th>Операция</th>
                                                <th>Количество</th>
                                                <th>Описание</th>
                                                <th>Пользователь</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($operations as $operation): ?>
                                                <tr>
                                                    <td>
                                                        <small><?= date('d.m.Y H:i', strtotime($operation['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars($operation['warehouse_name']) ?></small>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars($operation['template_name']) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        // Маппинг существующих типов операций на понятные названия
                                                        $operation_labels = [
                                                            'arrival' => ['Приход', 'success'],
                                                            'sale' => ['Расход', 'danger'],
                                                            'transfer' => ['Перемещение', 'primary'],
                                                            'adjustment' => ['Корректировка', 'warning']
                                                        ];
                                                        $label_info = $operation_labels[$operation['operation_type']] ?? ['Неизвестно', 'secondary'];
                                                        ?>
                                                        <span class="badge bg-<?= $label_info[1] ?>">
                                                            <?= $label_info[0] ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?= number_format(abs($operation['quantity_change']), 3) ?>
                                                        <small class="text-muted"><?= htmlspecialchars($operation['template_unit']) ?></small>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars($operation['notes'] ?: '-') ?></small>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?= htmlspecialchars(($operation['first_name'] ?? '') . ' ' . ($operation['last_name'] ?? '')) ?>
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
            </div>
        </div>
    </div>
</div>

<script>
// Обновление единицы измерения при выборе товара
document.getElementById('template_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'ед.';
    document.getElementById('unit-display').textContent = unit;
});

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    const templateSelect = document.getElementById('template_id');
    if (templateSelect.value) {
        const selectedOption = templateSelect.options[templateSelect.selectedIndex];
        const unit = selectedOption.getAttribute('data-unit') || 'ед.';
        document.getElementById('unit-display').textContent = unit;
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>