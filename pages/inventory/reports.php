<?php
/**
 * Отчеты по остаткам и движению товаров
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

$pageTitle = 'Отчеты по остаткам';
$currentPage = 'inventory';

// Параметры отчетов
$report_type = $_GET['report_type'] ?? 'current_stock';
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$template_id = (int)($_GET['template_id'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$company_id = (int)($_GET['company_id'] ?? 0);
$export = $_GET['export'] ?? '';

try {
    $pdo = getDBConnection();
    
    // Получаем справочные данные
    $warehouses_stmt = $pdo->query("
        SELECT w.id, w.name, c.name as company_name 
        FROM warehouses w 
        JOIN companies c ON w.company_id = c.id
        WHERE w.status = 1 
        ORDER BY c.name, w.name
    ");
    $warehouses = $warehouses_stmt->fetchAll();
    
    $templates_stmt = $pdo->query("
        SELECT id, name, unit 
        FROM product_templates 
        WHERE status = 1 
        ORDER BY name
    ");
    $templates = $templates_stmt->fetchAll();
    
    $companies_stmt = $pdo->query("
        SELECT id, name 
        FROM companies 
        WHERE status = 1 
        ORDER BY name
    ");
    $companies = $companies_stmt->fetchAll();
    
    // Генерируем отчет в зависимости от типа
    $report_data = [];
    $report_title = '';
    $report_columns = [];
    
    switch ($report_type) {
        case 'current_stock':
            $report_title = 'Текущие остатки на складах';
            $report_columns = ['Склад', 'Компания', 'Товар', 'Всего', 'Резерв', 'Доступно', 'Единица', 'Обновлено'];
            
            $where_conditions = ['i.quantity > 0 OR i.reserved_quantity > 0'];
            $params = [];
            
            if ($warehouse_id) {
                $where_conditions[] = 'i.warehouse_id = ?';
                $params[] = $warehouse_id;
            }
            
            if ($template_id) {
                $where_conditions[] = 'i.template_id = ?';
                $params[] = $template_id;
            }
            
            if ($company_id) {
                $where_conditions[] = 'c.id = ?';
                $params[] = $company_id;
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            $sql = "
                SELECT 
                    w.name as warehouse_name,
                    c.name as company_name,
                    pt.name as template_name,
                    i.quantity,
                    i.reserved_quantity,
                    (i.quantity - i.reserved_quantity) as available_quantity,
                    pt.unit,
                    i.last_updated
                FROM inventory i
                JOIN warehouses w ON i.warehouse_id = w.id
                JOIN companies c ON w.company_id = c.id
                JOIN product_templates pt ON i.template_id = pt.id
                WHERE $where_clause
                ORDER BY c.name, w.name, pt.name
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;
            
        case 'zero_stock':
            $report_title = 'Товары с нулевыми остатками';
            $report_columns = ['Склад', 'Компания', 'Товар', 'Последнее движение', 'Единица'];
            
            $where_conditions = ['1=1'];
            $params = [];
            
            if ($warehouse_id) {
                $where_conditions[] = 'w.id = ?';
                $params[] = $warehouse_id;
            }
            
            if ($company_id) {
                $where_conditions[] = 'c.id = ?';
                $params[] = $company_id;
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            // Товары, которые были на складе, но сейчас их нет
            $sql = "
                SELECT DISTINCT
                    w.name as warehouse_name,
                    c.name as company_name,
                    pt.name as template_name,
                    MAX(wo.created_at) as last_movement,
                    pt.unit
                FROM warehouse_operations wo
                JOIN warehouses w ON wo.warehouse_id = w.id
                JOIN companies c ON w.company_id = c.id
                JOIN products p ON wo.product_id = p.id
                JOIN product_templates pt ON p.template_id = pt.id
                LEFT JOIN inventory i ON w.id = i.warehouse_id AND pt.id = i.template_id
                WHERE $where_clause
                AND (i.quantity IS NULL OR i.quantity = 0)
                AND (i.reserved_quantity IS NULL OR i.reserved_quantity = 0)
                GROUP BY w.id, pt.id
                ORDER BY c.name, w.name, pt.name
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;
            
        case 'movement_summary':
            $report_title = 'Сводка движения товаров';
            $report_columns = ['Склад', 'Товар', 'Приход', 'Расход', 'Корректировки', 'Итого изменение', 'Единица'];
            
            $where_conditions = ['DATE(wo.created_at) BETWEEN ? AND ?'];
            $params = [$date_from, $date_to];
            
            if ($warehouse_id) {
                $where_conditions[] = 'wo.warehouse_id = ?';
                $params[] = $warehouse_id;
            }
            
            if ($template_id) {
                $where_conditions[] = 'p.template_id = ?';
                $params[] = $template_id;
            }
            
            if ($company_id) {
                $where_conditions[] = 'c.id = ?';
                $params[] = $company_id;
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            $sql = "
                SELECT 
                    w.name as warehouse_name,
                    pt.name as template_name,
                    SUM(CASE WHEN wo.operation_type = 'arrival' THEN wo.quantity_change ELSE 0 END) as arrivals,
                    SUM(CASE WHEN wo.operation_type = 'sale' THEN ABS(wo.quantity_change) ELSE 0 END) as sales,
                    SUM(CASE WHEN wo.operation_type IN ('adjustment', 'transfer') THEN wo.quantity_change ELSE 0 END) as adjustments,
                    SUM(wo.quantity_change) as total_change,
                    pt.unit
                FROM warehouse_operations wo
                JOIN warehouses w ON wo.warehouse_id = w.id
                JOIN companies c ON w.company_id = c.id
                JOIN products p ON wo.product_id = p.id
                JOIN product_templates pt ON p.template_id = pt.id
                WHERE $where_clause
                GROUP BY w.id, pt.id
                HAVING total_change != 0
                ORDER BY w.name, pt.name
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;
            

    }
    
    // Экспорт в CSV
    if ($export === 'csv' && !empty($report_data)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report_type . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // BOM для корректного отображения в Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Заголовки
        fputcsv($output, $report_columns, ';');
        
        // Данные
        foreach ($report_data as $row) {
            $csv_row = array_values($row);
            fputcsv($output, $csv_row, ';');
        }
        
        fclose($output);
        exit;
    }
    
} catch (Exception $e) {
    logError('Inventory reports error: ' . $e->getMessage());
    $error_message = 'Ошибка при генерации отчета: ' . $e->getMessage();
    $report_data = [];
    $warehouses = [];
    $templates = [];
    $companies = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">
                    <i class="bi bi-graph-up"></i>
                    Отчеты по остаткам
                </h1>
                <div class="btn-group" role="group">
                    <a href="/pages/inventory/index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        К остаткам
                    </a>
                    <a href="/pages/inventory/movement.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left-right"></i>
                        Движение товаров
                    </a>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Параметры отчета -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-sliders"></i>
                        Параметры отчета
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="report_type" class="form-label">Тип отчета</label>
                                <select class="form-select" id="report_type" name="report_type" onchange="toggleDateFields()">
                                    <option value="current_stock" <?= $report_type === 'current_stock' ? 'selected' : '' ?>>
                                        Текущие остатки
                                    </option>
                                    <option value="zero_stock" <?= $report_type === 'zero_stock' ? 'selected' : '' ?>>
                                        Нулевые остатки
                                    </option>
                                    <option value="movement_summary" <?= $report_type === 'movement_summary' ? 'selected' : '' ?>>
                                        Сводка движения
                                    </option>

                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="company_id" class="form-label">Компания</label>
                                <select class="form-select" id="company_id" name="company_id">
                                    <option value="">Все компании</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?= $company['id'] ?>" 
                                                <?= $company_id == $company['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($company['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="warehouse_id" class="form-label">Склад</label>
                                <select class="form-select" id="warehouse_id" name="warehouse_id">
                                    <option value="">Все склады</option>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <option value="<?= $warehouse['id'] ?>" 
                                                <?= $warehouse_id == $warehouse['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($warehouse['name']) ?> 
                                            (<?= htmlspecialchars($warehouse['company_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="template_id" class="form-label">Тип товара</label>
                                <select class="form-select" id="template_id" name="template_id">
                                    <option value="">Все типы</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>" 
                                                <?= $template_id == $template['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($template['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-search"></i>
                                    Сформировать
                                </button>
                                <?php if (!empty($report_data)): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" 
                                       class="btn btn-success">
                                        <i class="bi bi-download"></i>
                                        CSV
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Поля дат (показываются только для отчета по движению) -->
                        <div class="row g-3 mt-2" id="date-fields" style="display: <?= $report_type === 'movement_summary' ? 'flex' : 'none' ?>">
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
                        </div>
                    </form>
                </div>
            </div>

            <!-- Результаты отчета -->
            <?php if (!empty($report_data)): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-table"></i>
                            <?= htmlspecialchars($report_title) ?>
                        </h5>
                        <span class="badge bg-primary">
                            Записей: <?= count($report_data) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <?php foreach ($report_columns as $column): ?>
                                            <th><?= htmlspecialchars($column) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php if ($report_type === 'current_stock'): ?>
                                                <td><?= htmlspecialchars($row['warehouse_name']) ?></td>
                                                <td><?= htmlspecialchars($row['company_name']) ?></td>
                                                <td><?= htmlspecialchars($row['template_name']) ?></td>
                                                <td>
                                                    <span class="badge bg-primary">
                                                        <?= number_format($row['quantity'], 3) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($row['reserved_quantity'] > 0): ?>
                                                        <span class="badge bg-warning">
                                                            <?= number_format($row['reserved_quantity'], 3) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $row['available_quantity'] > 0 ? 'bg-success' : 'bg-danger' ?>">
                                                        <?= number_format($row['available_quantity'], 3) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($row['unit']) ?></td>
                                                <td>
                                                    <small><?= date('d.m.Y H:i', strtotime($row['last_updated'])) ?></small>
                                                </td>
                                            <?php elseif ($report_type === 'zero_stock'): ?>
                                                <td><?= htmlspecialchars($row['warehouse_name']) ?></td>
                                                <td><?= htmlspecialchars($row['company_name']) ?></td>
                                                <td><?= htmlspecialchars($row['template_name']) ?></td>
                                                <td>
                                                    <?php if ($row['last_movement']): ?>
                                                        <small><?= date('d.m.Y H:i', strtotime($row['last_movement'])) ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($row['unit']) ?></td>
                                            <?php elseif ($report_type === 'movement_summary'): ?>
                                                <td><?= htmlspecialchars($row['warehouse_name']) ?></td>
                                                <td><?= htmlspecialchars($row['template_name']) ?></td>
                                                <td>
                                                    <span class="badge bg-success">
                                                        +<?= number_format($row['arrivals'], 3) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger">
                                                        -<?= number_format($row['sales'], 3) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning">
                                                        <?= number_format($row['adjustments'], 3, '.', '') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $row['total_change'] >= 0 ? 'bg-success' : 'bg-danger' ?>">
                                                        <?= ($row['total_change'] >= 0 ? '+' : '') . number_format($row['total_change'], 3) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($row['unit']) ?></td>

                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php elseif (isset($report_data)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-inbox text-muted" style="font-size: 4rem;"></i>
                        <h4 class="text-muted mt-3">Данные не найдены</h4>
                        <p class="text-muted">Попробуйте изменить параметры отчета</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleDateFields() {
    const reportType = document.getElementById('report_type').value;
    const dateFields = document.getElementById('date-fields');
    
    if (reportType === 'movement_summary') {
        dateFields.style.display = 'flex';
    } else {
        dateFields.style.display = 'none';
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    toggleDateFields();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>