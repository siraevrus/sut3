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
$pageTitle = 'Реализация товаров';

// Параметры фильтрации и пагинации
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$warehouseFilter = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$dateFromFilter = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateToFilter = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$buyerFilter = isset($_GET['buyer']) ? trim($_GET['buyer']) : '';
$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $pdo = getDBConnection();
    
    // Строим условия WHERE с учетом прав доступа
    $whereConditions = [];
    $params = [];
    
    // Ограничения по роли пользователя
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $whereConditions[] = "s.warehouse_id = ?";
        $params[] = $currentUser['warehouse_id'];
    }
    
    // Фильтр по складу
    if ($warehouseFilter > 0) {
        $whereConditions[] = "s.warehouse_id = ?";
        $params[] = $warehouseFilter;
    }
    
    // Фильтр по дате от
    if (!empty($dateFromFilter)) {
        $whereConditions[] = "s.sale_date >= ?";
        $params[] = $dateFromFilter;
    }
    
    // Фильтр по дате до
    if (!empty($dateToFilter)) {
        $whereConditions[] = "s.sale_date <= ?";
        $params[] = $dateToFilter;
    }
    
    // Фильтр по покупателю
    if (!empty($buyerFilter)) {
        $whereConditions[] = "s.buyer LIKE ?";
        $params[] = '%' . $buyerFilter . '%';
    }
    
    // Поиск по названию шаблона
    if (!empty($searchFilter)) {
        $whereConditions[] = "pt.name LIKE ?";
        $params[] = '%' . $searchFilter . '%';
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Подсчитываем общее количество записей
    $countQuery = "
        SELECT COUNT(*) 
        FROM sales s
        JOIN warehouses w ON s.warehouse_id = w.id
        JOIN product_templates pt ON s.template_id = pt.id
        JOIN companies c ON w.company_id = c.id
        JOIN users u ON s.created_by = u.id
        $whereClause
    ";
    
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalRecords = $stmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);
    
    // Получаем данные с пагинацией
    $query = "
        SELECT 
            s.id,
            s.sale_date,
            s.buyer,
            s.quantity,
            s.price_cashless,
            s.price_cash,
            s.total_amount,
            s.exchange_rate,
            s.created_at,
            pt.name as template_name,
            pt.unit,
            w.name as warehouse_name,
            c.name as company_name,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM sales s
        JOIN warehouses w ON s.warehouse_id = w.id
        JOIN product_templates pt ON s.template_id = pt.id
        JOIN companies c ON w.company_id = c.id
        JOIN users u ON s.created_by = u.id
        $whereClause
        ORDER BY s.sale_date DESC, s.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([...$params, $limit, $offset]);
    $sales = $stmt->fetchAll();
    
    // Получаем список складов для фильтра
    $warehousesQuery = "
        SELECT w.id, w.name, c.name as company_name
        FROM warehouses w 
        JOIN companies c ON w.company_id = c.id 
        WHERE w.status = 1
    ";
    
    // Ограничиваем склады для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $warehousesQuery .= " AND w.id = " . $currentUser['warehouse_id'];
    }
    
    $warehousesQuery .= " ORDER BY c.name, w.name";
    
    $stmt = $pdo->prepare($warehousesQuery);
    $stmt->execute();
    $warehouses = $stmt->fetchAll();
    
    // Статистика
    $statsQuery = "
        SELECT 
            COUNT(*) as total_sales,
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN sale_date = CURDATE() THEN total_amount ELSE 0 END), 0) as today_revenue
        FROM sales s
        JOIN warehouses w ON s.warehouse_id = w.id
        $whereClause
    ";
    
    $stmt = $pdo->prepare($statsQuery);
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError('Sales list error: ' . $e->getMessage());
    $sales = [];
    $warehouses = [];
    $stats = ['total_sales' => 0, 'total_revenue' => 0, 'today_revenue' => 0];
    $totalRecords = 0;
    $totalPages = 0;
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <?php if (hasAccessToSection('sales', 'create')): ?>
                    <a href="warehouse_select.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Добавить реализацию
                    </a>
                <?php endif; ?>
            </div>

            <!-- Статистика -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">Всего реализаций</h5>
                                    <h3 class="mb-0"><?= number_format($stats['total_sales']) ?></h3>
                                </div>
                                <div class="flex-shrink-0">
                                    <i class="bi bi-receipt" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">Общая выручка</h5>
                                    <h3 class="mb-0">$<?= number_format($stats['total_revenue'], 2) ?></h3>
                                </div>
                                <div class="flex-shrink-0">
                                    <i class="bi bi-currency-dollar" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">Выручка сегодня</h5>
                                    <h3 class="mb-0">$<?= number_format($stats['today_revenue'], 2) ?></h3>
                                </div>
                                <div class="flex-shrink-0">
                                    <i class="bi bi-graph-up" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Фильтры -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="warehouse_id" class="form-label">Склад</label>
                            <select name="warehouse_id" id="warehouse_id" class="form-select">
                                <option value="">Все склады</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?= $warehouse['id'] ?>" 
                                            <?= $warehouseFilter == $warehouse['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($warehouse['company_name']) ?> - <?= htmlspecialchars($warehouse['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">Дата от</label>
                            <input type="date" name="date_from" id="date_from" class="form-control" 
                                   value="<?= htmlspecialchars($dateFromFilter) ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">Дата до</label>
                            <input type="date" name="date_to" id="date_to" class="form-control" 
                                   value="<?= htmlspecialchars($dateToFilter) ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="buyer" class="form-label">Покупатель</label>
                            <input type="text" name="buyer" id="buyer" class="form-control" 
                                   placeholder="Поиск по покупателю" value="<?= htmlspecialchars($buyerFilter) ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="search" class="form-label">Товар</label>
                            <input type="text" name="search" id="search" class="form-control" 
                                   placeholder="Поиск по товару" value="<?= htmlspecialchars($searchFilter) ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Таблица реализаций -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($sales)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h4 class="text-muted">Реализации не найдены</h4>
                            <p class="text-muted">Попробуйте изменить параметры поиска или добавить новую реализацию</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Дата</th>
                                        <th>Покупатель</th>
                                        <th>Товар</th>
                                        <th>Количество</th>
                                        <th>Склад</th>
                                        <th>Сумма</th>
                                        <th>Сотрудник</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales as $sale): ?>
                                        <tr>
                                            <td><?= date('d.m.Y', strtotime($sale['sale_date'])) ?></td>
                                            <td><?= htmlspecialchars($sale['buyer']) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($sale['template_name']) ?></strong>
                                            </td>
                                            <td>
                                                <?= number_format($sale['quantity'], 3) ?> <?= htmlspecialchars($sale['unit']) ?>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($sale['company_name']) ?></small><br>
                                                <?= htmlspecialchars($sale['warehouse_name']) ?>
                                            </td>
                                            <td>
                                                <strong>$<?= number_format($sale['total_amount'], 2) ?></strong>
                                                <?php if ($sale['price_cashless'] > 0 && $sale['price_cash'] > 0): ?>
                                                    <br><small class="text-muted">
                                                        Б/Н: $<?= number_format($sale['price_cashless'], 2) ?> | 
                                                        Нал: $<?= number_format($sale['price_cash'], 2) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($sale['created_by_name']) ?></small><br>
                                                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($sale['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="view.php?id=<?= $sale['id'] ?>" 
                                                       class="btn btn-outline-primary" title="Просмотр">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if (hasAccessToSection('sales', 'edit')): ?>
                                                        <a href="edit.php?id=<?= $sale['id'] ?>" 
                                                           class="btn btn-outline-secondary" title="Редактировать">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Пагинация -->
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Пагинация">
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Предыдущая</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Следующая</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>