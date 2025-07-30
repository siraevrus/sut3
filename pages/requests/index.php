<?php
/**
 * Список запросов
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('requests');

$pageTitle = 'Запросы';

// Параметры фильтрации и пагинации
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$template_id = (int)($_GET['template_id'] ?? 0);
$employee_id = (int)($_GET['employee_id'] ?? 0);
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

// Обработка действий
$success = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

try {
    $pdo = getDBConnection();
    $user = getCurrentUser();
    
    // Строим запрос с фильтрами в зависимости от роли
    $where = ['1=1'];
    $bindings = [];
    
    // Права доступа по ролям
    if ($user['role'] === ROLE_WAREHOUSE_WORKER) {
        // Работник склада видит только свои запросы
        $where[] = "r.created_by = ?";
        $bindings[] = $user['id'];
    } elseif ($user['role'] === ROLE_SALES_MANAGER) {
        // Менеджер по продажам видит все запросы
        // Никаких дополнительных ограничений
    } elseif ($user['role'] === ROLE_ADMIN) {
        // Администратор видит все запросы
        // Никаких дополнительных ограничений
    }
    
    // Фильтры
    if (!empty($search)) {
        $where[] = "(pt.name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
    }
    
    if ($status !== '') {
        $where[] = "r.status = ?";
        $bindings[] = $status;
    }
    
    if ($template_id > 0) {
        $where[] = "r.template_id = ?";
        $bindings[] = $template_id;
    }
    
    if ($employee_id > 0) {
        $where[] = "r.created_by = ?";
        $bindings[] = $employee_id;
    }
    
    if (!empty($date_from)) {
        $where[] = "DATE(r.created_at) >= ?";
        $bindings[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where[] = "DATE(r.created_at) <= ?";
        $bindings[] = $date_to;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Подсчитываем общее количество
    $countQuery = "
        SELECT COUNT(*) 
        FROM requests r
        LEFT JOIN product_templates pt ON r.template_id = pt.id
        LEFT JOIN users u ON r.created_by = u.id
        WHERE $whereClause
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($bindings);
    $total = $stmt->fetchColumn();
    
    // Получаем данные с пагинацией
    $dataQuery = "
        SELECT 
            r.*,
            pt.name as template_name,
            u.first_name, u.last_name,
            w.name as warehouse_name,
            c.name as company_name,
            processor.first_name as processor_first_name,
            processor.last_name as processor_last_name
        FROM requests r
        LEFT JOIN product_templates pt ON r.template_id = pt.id
        LEFT JOIN users u ON r.created_by = u.id
        LEFT JOIN warehouses w ON r.warehouse_id = w.id
        LEFT JOIN companies c ON w.company_id = c.id
        LEFT JOIN users processor ON r.processed_by = processor.id
        WHERE $whereClause
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($dataQuery);
    $stmt->execute([...$bindings, $limit, $offset]);
    $requests = $stmt->fetchAll();
    
    $totalPages = ceil($total / $limit);
    
    // Получаем списки для фильтров
    $templates = [];
    $employees = [];
    
    if ($user['role'] === ROLE_ADMIN) {
        // Шаблоны товаров
        $stmt = $pdo->query("SELECT id, name FROM product_templates WHERE status = 1 ORDER BY name");
        $templates = $stmt->fetchAll();
        
        // Сотрудники (только те, кто может создавать запросы)
        $stmt = $pdo->query("
            SELECT id, first_name, last_name 
            FROM users 
            WHERE role IN ('warehouse_worker', 'sales_manager') AND status = 1 
            ORDER BY first_name, last_name
        ");
        $employees = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    logError('Requests list error: ' . $e->getMessage());
    $error = 'Произошла ошибка при загрузке данных';
    $requests = [];
    $total = 0;
    $totalPages = 0;
    $templates = [];
    $employees = [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Управление запросами на товары</p>
    </div>
    <div>
        <?php if ($user['role'] === ROLE_WAREHOUSE_WORKER || $user['role'] === ROLE_SALES_MANAGER): ?>
            <a href="/pages/requests/create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i>
                Отправить запрос
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= e($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Фильтры -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="search" class="form-label">Поиск</label>
                <input type="text" class="form-control" id="search" name="search" 
                       value="<?= e($search) ?>" placeholder="Товар или сотрудник">
            </div>
            
            <div class="col-md-2">
                <label for="status" class="form-label">Статус</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Все статусы</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Не обработан</option>
                    <option value="processed" <?= $status === 'processed' ? 'selected' : '' ?>>Обработан</option>
                </select>
            </div>
            
            <?php if ($user['role'] === ROLE_ADMIN && !empty($templates)): ?>
                <div class="col-md-2">
                    <label for="template_id" class="form-label">Тип товара</label>
                    <select class="form-select" id="template_id" name="template_id">
                        <option value="">Все типы</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?= $template['id'] ?>" <?= $template_id == $template['id'] ? 'selected' : '' ?>>
                                <?= e($template['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="employee_id" class="form-label">Сотрудник</label>
                    <select class="form-select" id="employee_id" name="employee_id">
                        <option value="">Все сотрудники</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= $employee['id'] ?>" <?= $employee_id == $employee['id'] ? 'selected' : '' ?>>
                                <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <div class="col-md-2">
                <label for="date_from" class="form-label">Дата с</label>
                <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($date_from) ?>">
            </div>
            
            <div class="col-md-2">
                <label for="date_to" class="form-label">Дата по</label>
                <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($date_to) ?>">
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

<!-- Список запросов -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="bi bi-list-ul"></i>
            Запросы (<?= number_format($total) ?>)
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                <h5 class="mt-3">Запросы не найдены</h5>
                <p class="text-muted">
                    <?php if ($user['role'] === ROLE_WAREHOUSE_WORKER || $user['role'] === ROLE_SALES_MANAGER): ?>
                        Создайте первый запрос на товар
                    <?php else: ?>
                        Запросы от сотрудников появятся здесь
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Сотрудник</th>
                            <th>Товар</th>
                            <th>Склад</th>
                            <th>Количество</th>
                            <th>Дата поставки</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td>
                                    <small class="text-muted">
                                        <?= date('d.m.Y H:i', strtotime($request['created_at'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= e($request['first_name'] . ' ' . $request['last_name']) ?>
                                    <?php if ($request['company_name']): ?>
                                        <br><small class="text-muted"><?= e($request['company_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e($request['template_name']) ?></strong>
                                    <?php if ($request['description']): ?>
                                        <br><small class="text-muted"><?= e(mb_substr($request['description'], 0, 50) . (mb_strlen($request['description']) > 50 ? '...' : '')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($request['warehouse_name']) ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= number_format($request['quantity'], 0) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $request['delivery_date'] ? date('d.m.Y', strtotime($request['delivery_date'])) : '-' ?>
                                </td>
                                <td>
                                    <?php if ($request['status'] === 'processed'): ?>
                                        <span class="badge bg-success">Обработан</span>
                                        <?php if ($request['processor_first_name']): ?>
                                            <br><small class="text-muted">
                                                <?= e($request['processor_first_name'] . ' ' . $request['processor_last_name']) ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Не обработан</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="/pages/requests/view.php?id=<?= $request['id'] ?>" 
                                           class="btn btn-outline-primary" title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        
                                        <?php if ($request['status'] === 'pending'): ?>
                                            <?php if (($user['role'] === ROLE_WAREHOUSE_WORKER || $user['role'] === ROLE_SALES_MANAGER) && $request['created_by'] == $user['id']): ?>
                                                <a href="/pages/requests/edit.php?id=<?= $request['id'] ?>" 
                                                   class="btn btn-outline-secondary" title="Редактировать">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($user['role'] === ROLE_ADMIN): ?>
                                                <form method="POST" action="/pages/requests/process.php" class="d-inline">
                                                    <?= generateCSRFToken() ?>
                                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                    <input type="hidden" name="action" value="process">
                                                    <button type="submit" class="btn btn-outline-success" title="Обработать">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($user['role'] === ROLE_ADMIN): ?>
                                                <form method="POST" action="/pages/requests/process.php" class="d-inline">
                                                    <?= generateCSRFToken() ?>
                                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                    <input type="hidden" name="action" value="unprocess">
                                                    <button type="submit" class="btn btn-outline-warning" title="Отменить обработку">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <nav aria-label="Пагинация запросов">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>