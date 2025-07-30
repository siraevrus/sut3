<?php
/**
 * Остатки на складах
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

$pageTitle = 'Остатки на складах';
$currentPage = 'inventory';

// Параметры фильтрации и пагинации
$search = $_GET['search'] ?? '';
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$template_id = (int)($_GET['template_id'] ?? 0);
$show_zero = isset($_GET['show_zero']) ? 1 : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $pdo = getDBConnection();
    
    // Получаем список складов для фильтра
    $warehouses_stmt = $pdo->query("
        SELECT id, name 
        FROM warehouses 
        WHERE status = 1 
        ORDER BY name
    ");
    $warehouses = $warehouses_stmt->fetchAll();
    
    // Получаем список шаблонов для фильтра
    $templates_stmt = $pdo->query("
        SELECT id, name 
        FROM product_templates 
        WHERE status = 1 
        ORDER BY name
    ");
    $templates = $templates_stmt->fetchAll();
    
    // Строим запрос для получения остатков
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
    
    if ($search) {
        $where_conditions[] = '(w.name LIKE ? OR pt.name LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!$show_zero) {
        $where_conditions[] = 'i.quantity > 0';
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Запрос для подсчета общего количества записей
    $count_sql = "
        SELECT COUNT(*) 
        FROM inventory i
        JOIN warehouses w ON i.warehouse_id = w.id
        JOIN product_templates pt ON i.template_id = pt.id
        WHERE $where_clause
    ";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Основной запрос для получения остатков
    $sql = "
        SELECT 
            i.*,
            w.name as warehouse_name,
            w.address as warehouse_address,
            pt.name as template_name,
            pt.unit as template_unit,
            pt.formula as template_formula,
            c.name as company_name
        FROM inventory i
        JOIN warehouses w ON i.warehouse_id = w.id
        JOIN product_templates pt ON i.template_id = pt.id
        JOIN companies c ON w.company_id = c.id
        WHERE $where_clause
        ORDER BY w.name, pt.name
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inventory_items = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError('Inventory list error: ' . $e->getMessage());
    $error_message = 'Ошибка при загрузке остатков: ' . $e->getMessage();
    $inventory_items = [];
    $total_pages = 0;
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
                    <i class="bi bi-boxes"></i>
                    Остатки на складах
                </h1>
                <div class="btn-group" role="group">
                    <a href="/pages/inventory/movement.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left-right"></i>
                        Движение товаров
                    </a>
                    <a href="/pages/inventory/reports.php" class="btn btn-outline-primary">
                        <i class="bi bi-graph-up"></i>
                        Отчеты
                    </a>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Фильтры -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="search" class="form-label">Поиск</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Поиск по складу или товару">
                        </div>
                        <div class="col-md-3">
                            <label for="warehouse_id" class="form-label">Склад</label>
                            <select class="form-select" id="warehouse_id" name="warehouse_id">
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
                            <div class="me-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="show_zero" name="show_zero" 
                                           <?= $show_zero ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="show_zero">
                                        Показать нулевые остатки
                                    </label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i>
                                Найти
                            </button>
                        </div>
                    </form>
                </div>
            </div>



            <!-- Таблица остатков -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($inventory_items)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox text-muted" style="font-size: 4rem;"></i>
                            <h4 class="text-muted mt-3">Остатки не найдены</h4>
                            <p class="text-muted">Попробуйте изменить параметры поиска</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Склад</th>
                                        <th>Тип товара</th>
                                        <th>Компания</th>
                                        <th>Количество</th>
                                        <th>Единица измерения</th>
                                        <th>Обновлено</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory_items as $item): ?>
                                        <tr class="<?= $item['quantity'] <= 0 ? 'table-warning' : '' ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($item['warehouse_name']) ?></strong>
                                                <?php if ($item['warehouse_address']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($item['warehouse_address']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($item['template_name']) ?></strong>
                                                <?php if ($item['template_formula']): ?>
                                                    <br><small class="text-muted">
                                                        <i class="bi bi-calculator"></i>
                                                        <?= htmlspecialchars($item['template_formula']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($item['company_name']) ?></td>
                                            <td>
                                                <span class="badge <?= $item['quantity'] > 0 ? 'bg-success' : 'bg-danger' ?>">
                                                    <?= number_format($item['quantity'], 3) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($item['template_unit']) ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= date('d.m.Y H:i', strtotime($item['last_updated'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="/pages/inventory/details.php?warehouse_id=<?= $item['warehouse_id'] ?>&template_id=<?= $item['template_id'] ?>" 
                                                       class="btn btn-outline-primary" title="Подробности">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="/pages/inventory/movement.php?warehouse_id=<?= $item['warehouse_id'] ?>&template_id=<?= $item['template_id'] ?>" 
                                                       class="btn btn-outline-success" title="Движение">
                                                        <i class="bi bi-arrow-left-right"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Пагинация -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Пагинация остатков">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                                        if ($start_page > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        $active = $i == $page ? 'active' : '';
                                        echo '<li class="page-item ' . $active . '"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '">' . $i . '</a></li>';
                                    }
                                    
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                                    }
                                    ?>
                                    
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>