<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Проверяем авторизацию и права доступа
if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

// Проверяем права доступа к разделу товаров в пути
if (!hasAccessToSection('goods_in_transit')) {
    redirect('/pages/errors/403.php');
}

$currentUser = getCurrentUser();
$pageTitle = 'Товар в пути';

// Параметры фильтрации и пагинации
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$warehouseFilter = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFromFilter = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateToFilter = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $pdo = getDBConnection();
    
    // Строим условия WHERE с учетом прав доступа
    $whereConditions = [];
    $params = [];
    
    // Ограничения по роли пользователя
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $whereConditions[] = "git.warehouse_id = ?";
        $params[] = $currentUser['warehouse_id'];
    }
    
    // Фильтр по складу
    if ($warehouseFilter > 0) {
        $whereConditions[] = "git.warehouse_id = ?";
        $params[] = $warehouseFilter;
    }
    
    // Фильтр по статусу
    if (!empty($statusFilter)) {
        $whereConditions[] = "git.status = ?";
        $params[] = $statusFilter;
    }
    
    // Фильтр по дате отгрузки от
    if (!empty($dateFromFilter)) {
        $whereConditions[] = "git.departure_date >= ?";
        $params[] = $dateFromFilter;
    }
    
    // Фильтр по дате отгрузки до
    if (!empty($dateToFilter)) {
        $whereConditions[] = "git.departure_date <= ?";
        $params[] = $dateToFilter;
    }
    
    // Поиск по месту отгрузки
    if (!empty($searchFilter)) {
        $whereConditions[] = "git.departure_location LIKE ?";
        $params[] = '%' . $searchFilter . '%';
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Подсчитываем общее количество записей
    $countQuery = "
        SELECT COUNT(*) 
        FROM goods_in_transit git
        JOIN warehouses w ON git.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        JOIN users u ON git.created_by = u.id
        $whereClause
    ";
    
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalRecords = $stmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);
    
    // Получаем данные с пагинацией
    $query = "
        SELECT 
            git.id,
            git.departure_location,
            git.departure_date,
            git.arrival_date,
            git.status,
            git.created_at,
            git.confirmed_at,
            w.name as warehouse_name,
            c.name as company_name,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            CONCAT(uc.first_name, ' ', uc.last_name) as confirmed_by_name,
            JSON_LENGTH(git.goods_info) as goods_count,
            CASE 
                WHEN git.files IS NOT NULL THEN JSON_LENGTH(git.files) 
                ELSE 0 
            END as files_count
        FROM goods_in_transit git
        JOIN warehouses w ON git.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        JOIN users u ON git.created_by = u.id
        LEFT JOIN users uc ON git.confirmed_by = uc.id
        $whereClause
        ORDER BY 
            CASE git.status 
                WHEN 'in_transit' THEN 1 
                WHEN 'arrived' THEN 2 
                WHEN 'confirmed' THEN 3 
            END,
            git.departure_date DESC, 
            git.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([...$params, $limit, $offset]);
    $transitItems = $stmt->fetchAll();
    
    // Получаем список складов для фильтра
    $warehousesQuery = "
        SELECT w.id, w.name, c.name as company_name
        FROM warehouses w 
        JOIN companies c ON w.company_id = c.id 
        WHERE w.status = 1
    ";
    
    // Ограичиваем склады для работника склада
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
            COUNT(*) as total_transit,
            SUM(CASE WHEN git.status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_count,
            SUM(CASE WHEN git.status = 'arrived' THEN 1 ELSE 0 END) as arrived_count,
            SUM(CASE WHEN git.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count
        FROM goods_in_transit git
        JOIN warehouses w ON git.warehouse_id = w.id
        $whereClause
    ";
    
    $stmt = $pdo->prepare($statsQuery);
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError('Transit list error: ' . $e->getMessage());
    $transitItems = [];
    $warehouses = [];
    $stats = ['total_transit' => 0, 'in_transit_count' => 0, 'arrived_count' => 0, 'confirmed_count' => 0];
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
                <?php if (hasAccessToSection('goods_in_transit', 'create')): ?>
                    <a href="create.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Добавить товар в пути
                    </a>
                <?php endif; ?>
            </div>

            <!-- Статистика -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">Всего отправлений</h5>
                                    <h3 class="mb-0"><?= number_format($stats['total_transit']) ?></h3>
                                </div>
                                <div class="flex-shrink-0">
                                    <i class="bi bi-truck" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">В пути</h5>
                                    <h3 class="mb-0"><?= number_format($stats['in_transit_count']) ?></h3>
                                </div>
                                <div class="flex-shrink-0">
                                    <i class="bi bi-clock" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">Прибыли</h5>
                                    <h3 class="mb-0"><?= number_format($stats['arrived_count']) ?></h3>
                                </div>
                                <div class="flex-shrink-0">
                                    <i class="bi bi-geo-alt" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">Подтверждены</h5>
                                    <h3 class="mb-0"><?= number_format($stats['confirmed_count']) ?></h3>
                                </div>
                                <div class="flex-shrink-0">
                                    <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
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
                            <label for="status" class="form-label">Статус</label>
                            <select name="status" id="status" class="form-select">
                                <option value="">Все статусы</option>
                                <option value="in_transit" <?= $statusFilter == 'in_transit' ? 'selected' : '' ?>>В пути</option>
                                <option value="arrived" <?= $statusFilter == 'arrived' ? 'selected' : '' ?>>Прибыл</option>
                                <option value="confirmed" <?= $statusFilter == 'confirmed' ? 'selected' : '' ?>>Подтвержден</option>
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
                            <label for="search" class="form-label">Место отгрузки</label>
                            <input type="text" name="search" id="search" class="form-control" 
                                   placeholder="Поиск по месту" value="<?= htmlspecialchars($searchFilter) ?>">
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

            <!-- Таблица товаров в пути -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($transitItems)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-truck display-1 text-muted"></i>
                            <h4 class="text-muted">Товары в пути не найдены</h4>
                            <p class="text-muted">Попробуйте изменить параметры поиска или добавить новую отправку</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Дата отгрузки</th>
                                        <th>Место отгрузки</th>
                                        <th>Дата поступления</th>
                                        <th>Склад</th>
                                        <th>Товаров</th>
                                        <th>Файлов</th>
                                        <th>Статус</th>
                                        <th>Сотрудник</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transitItems as $item): ?>
                                        <tr>
                                            <td><?= date('d.m.Y', strtotime($item['departure_date'])) ?></td>
                                            <td><?= htmlspecialchars($item['departure_location']) ?></td>
                                            <td><?= date('d.m.Y', strtotime($item['arrival_date'])) ?></td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($item['company_name']) ?></small><br>
                                                <?= htmlspecialchars($item['warehouse_name']) ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $item['goods_count'] ?></span>
                                            </td>
                                            <td>
                                                <?php if ($item['files_count'] > 0): ?>
                                                    <span class="badge bg-secondary"><?= $item['files_count'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $statusClass = match($item['status']) {
                                                    'in_transit' => 'bg-warning',
                                                    'arrived' => 'bg-info',
                                                    'confirmed' => 'bg-success',
                                                    default => 'bg-secondary'
                                                };
                                                $statusText = match($item['status']) {
                                                    'in_transit' => 'В пути',
                                                    'arrived' => 'Прибыл',
                                                    'confirmed' => 'Подтвержден',
                                                    default => $item['status']
                                                };
                                                ?>
                                                <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($item['created_by_name']) ?></small><br>
                                                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($item['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="view.php?id=<?= $item['id'] ?>" 
                                                       class="btn btn-outline-primary" title="Просмотр">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if (hasAccessToSection('goods_in_transit', 'edit') && $item['status'] !== 'confirmed'): ?>
                                                        <a href="edit.php?id=<?= $item['id'] ?>" 
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