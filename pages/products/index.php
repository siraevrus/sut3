<?php
/**
 * Список товаров
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('products');

$pageTitle = 'Управление товарами';

// Параметры поиска и фильтрации
$search = trim($_GET['search'] ?? '');
$template_id = (int)($_GET['template_id'] ?? 0);
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

// Обработка действий
$success = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

try {
    $pdo = getDBConnection();
    $user = getCurrentUser();
    
    // Получаем список шаблонов для фильтра
    $templatesStmt = $pdo->query("
        SELECT id, name 
        FROM product_templates 
        WHERE status = 1 
        ORDER BY name
    ");
    $templates = $templatesStmt->fetchAll();
    
    // Получаем список складов для фильтра (в зависимости от роли)
    if ($user['role'] === ROLE_ADMIN) {
        $warehousesStmt = $pdo->query("
            SELECT w.id, w.name, c.name as company_name
            FROM warehouses w
            JOIN companies c ON w.company_id = c.id
            WHERE w.status = 1 AND c.status = 1
            ORDER BY c.name, w.name
        ");
    } else {
        // Для других ролей показываем только склады их компании
        $warehousesStmt = $pdo->prepare("
            SELECT w.id, w.name, c.name as company_name
            FROM warehouses w
            JOIN companies c ON w.company_id = c.id
            WHERE w.status = 1 AND c.status = 1 AND w.company_id = ?
            ORDER BY w.name
        ");
        $warehousesStmt->execute([$user['company_id']]);
    }
    $warehouses = $warehousesStmt->fetchAll();
    
    // Строим запрос с фильтрами
    $where = ['1=1'];
    $bindings = [];
    
    if (!empty($search)) {
        $where[] = "(pt.name LIKE ? OR p.transport_number LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
    }
    
    if ($template_id > 0) {
        $where[] = "p.template_id = ?";
        $bindings[] = $template_id;
    }
    
    if ($warehouse_id > 0) {
        $where[] = "p.warehouse_id = ?";
        $bindings[] = $warehouse_id;
    }
    
    if (!empty($date_from)) {
        $where[] = "p.arrival_date >= ?";
        $bindings[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where[] = "p.arrival_date <= ?";
        $bindings[] = $date_to;
    }
    
    // Ограничения по роли пользователя
    if ($user['role'] !== ROLE_ADMIN) {
        $where[] = "w.company_id = ?";
        $bindings[] = $user['company_id'];
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Подсчитываем общее количество
    $countQuery = "
        SELECT COUNT(*) 
        FROM products p
        JOIN product_templates pt ON p.template_id = pt.id
        JOIN warehouses w ON p.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        WHERE $whereClause
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($bindings);
    $total = $stmt->fetchColumn();
    
    // Получаем данные с пагинацией
    $dataQuery = "
        SELECT 
            p.*,
            pt.name as template_name,
            pt.formula,
            w.name as warehouse_name,
            c.name as company_name,
            u.first_name as creator_name,
            u.last_name as creator_lastname
        FROM products p
        JOIN product_templates pt ON p.template_id = pt.id
        JOIN warehouses w ON p.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE $whereClause
        ORDER BY p.arrival_date DESC, p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($dataQuery);
    $stmt->execute([...$bindings, $limit, $offset]);
    $products = $stmt->fetchAll();
    
    $totalPages = ceil($total / $limit);
    
} catch (Exception $e) {
    logError('Products list error: ' . $e->getMessage());
    $error = 'Произошла ошибка при загрузке данных';
    $products = [];
    $total = 0;
    $totalPages = 0;
    $templates = [];
    $warehouses = [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Управление товарами на складах</p>
    </div>
    <div>
        <a href="/pages/products/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i>
            Добавить товар
        </a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle"></i> <?= e($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle"></i> <?= e($error) ?>
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
                       value="<?= e($search) ?>" placeholder="Название товара, номер ТС...">
            </div>
            
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
                <label for="warehouse_id" class="form-label">Склад</label>
                <select class="form-select" id="warehouse_id" name="warehouse_id">
                    <option value="">Все склады</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?= $warehouse['id'] ?>" <?= $warehouse_id == $warehouse['id'] ? 'selected' : '' ?>>
                        <?= e($warehouse['name']) ?>
                        <?php if (getCurrentUser()['role'] === ROLE_ADMIN): ?>
                        (<?= e($warehouse['company_name']) ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="date_from" class="form-label">Дата с</label>
                <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($date_from) ?>">
            </div>
            
            <div class="col-md-2">
                <label for="date_to" class="form-label">Дата по</label>
                <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($date_to) ?>">
            </div>
            
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary me-2">
                    <i class="bi bi-search"></i>
                </button>
                <a href="/pages/products/index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <i class="bi bi-box text-primary" style="font-size: 2rem;"></i>
                <h4 class="mt-2"><?= number_format($total) ?></h4>
                <p class="text-muted mb-0">Всего товаров</p>
            </div>
        </div>
    </div>
</div>

<!-- Список товаров -->
<?php if (empty($products)): ?>
<div class="empty-state">
    <i class="bi bi-box"></i>
    <h4>Товары не найдены</h4>
    <p>
        <?php if (!empty($search) || $template_id > 0 || $warehouse_id > 0 || !empty($date_from) || !empty($date_to)): ?>
            Попробуйте изменить параметры поиска или 
            <a href="/pages/products/index.php">сбросить фильтры</a>
        <?php else: ?>
            Добавьте первый товар для начала работы
        <?php endif; ?>
    </p>
    
    <?php if (empty($search) && $template_id == 0 && $warehouse_id == 0 && empty($date_from) && empty($date_to)): ?>
    <a href="/pages/products/create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i>
        Добавить товар
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Товар</th>
                <th>Склад</th>
                <th>Дата поступления</th>
                <th>Номер ТС</th>
                <th>Объем</th>
                <th>Добавил</th>
                <th width="120">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
            <?php
            // Декодируем атрибуты товара
            $attributes = json_decode($product['attributes'], true) ?? [];
            ?>
            <tr>
                <td>
                    <div>
                        <div class="fw-semibold">
                            <a href="/pages/products/view.php?id=<?= $product['id'] ?>" 
                               class="text-decoration-none">
                                <?= e($product['template_name']) ?>
                            </a>
                        </div>
                        <small class="text-muted">
                            <?php
                            // Показываем основные характеристики
                            $displayAttributes = array_slice($attributes, 0, 2);
                            $attrStrings = [];
                            foreach ($displayAttributes as $key => $value) {
                                $attrStrings[] = ucfirst($key) . ': ' . $value;
                            }
                            echo e(implode(', ', $attrStrings));
                            if (count($attributes) > 2) {
                                echo '...';
                            }
                            ?>
                        </small>
                    </div>
                </td>
                
                <td>
                    <div>
                        <div class="fw-semibold"><?= e($product['warehouse_name']) ?></div>
                        <?php if (getCurrentUser()['role'] === ROLE_ADMIN): ?>
                        <small class="text-muted"><?= e($product['company_name']) ?></small>
                        <?php endif; ?>
                    </div>
                </td>
                
                <td>
                    <span class="text-nowrap">
                        <?= date('d.m.Y', strtotime($product['arrival_date'])) ?>
                    </span>
                </td>
                
                <td>
                    <?php if ($product['transport_number']): ?>
                    <code><?= e($product['transport_number']) ?></code>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($product['calculated_volume']): ?>
                    <span class="badge bg-success">
                        <?= number_format($product['calculated_volume'], 3) ?> м³
                    </span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <small class="text-muted">
                        <?= e(trim($product['creator_lastname'] . ' ' . $product['creator_name'])) ?>
                    </small>
                </td>
                
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="/pages/products/view.php?id=<?= $product['id'] ?>" 
                           class="btn btn-outline-primary" title="Просмотр">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="/pages/products/edit.php?id=<?= $product['id'] ?>" 
                           class="btn btn-outline-secondary" title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <button type="button" class="btn btn-outline-danger" 
                                onclick="confirmDelete(<?= $product['id'] ?>)" title="Удалить">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Пагинация -->
<?php if ($totalPages > 1): ?>
<nav aria-label="Пагинация товаров">
    <ul class="pagination justify-content-center">
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
        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
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
<?php endif; ?>
<?php endif; ?>

<script>
function confirmDelete(productId) {
    if (confirm('Вы уверены, что хотите удалить этот товар? Это действие необратимо.')) {
        window.location.href = '/pages/products/delete.php?id=' + productId;
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>