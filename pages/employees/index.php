<?php
/**
 * Список сотрудников
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('employees');

$pageTitle = 'Управление сотрудниками';

// Параметры поиска и фильтрации
$search = trim($_GET['search'] ?? '');
$company_id = (int)($_GET['company_id'] ?? 0);
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

// Обработка действий
$success = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

try {
    $pdo = getDBConnection();
    
    // Получаем список компаний для фильтра
    $stmt = $pdo->query("SELECT id, name FROM companies WHERE status = 1 ORDER BY name");
    $companies = $stmt->fetchAll();
    
    // Получаем список складов для фильтра
    $warehousesQuery = "SELECT w.id, w.name, c.name as company_name 
                       FROM warehouses w 
                       JOIN companies c ON w.company_id = c.id 
                       WHERE w.status = 1 AND c.status = 1";
    if ($company_id) {
        $warehousesQuery .= " AND w.company_id = " . $company_id;
    }
    $warehousesQuery .= " ORDER BY c.name, w.name";
    $stmt = $pdo->query($warehousesQuery);
    $warehouses = $stmt->fetchAll();
    
    // Строим запрос с фильтрами
    $where = ['u.id > 0']; // Исключаем возможные системные записи
    $bindings = [];
    
    if (!empty($search)) {
        $where[] = "(CONCAT(u.last_name, ' ', u.first_name, ' ', IFNULL(u.middle_name, '')) LIKE ? 
                    OR u.login LIKE ? 
                    OR u.phone LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
    }
    
    if ($company_id) {
        $where[] = "u.company_id = ?";
        $bindings[] = $company_id;
    }
    
    if ($warehouse_id) {
        $where[] = "u.warehouse_id = ?";
        $bindings[] = $warehouse_id;
    }
    
    if (!empty($role)) {
        $where[] = "u.role = ?";
        $bindings[] = $role;
    }
    
    if ($status !== '') {
        $where[] = "u.status = ?";
        $bindings[] = $status;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Подсчитываем общее количество
    $countQuery = "
        SELECT COUNT(*) 
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        LEFT JOIN warehouses w ON u.warehouse_id = w.id
        WHERE $whereClause
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($bindings);
    $total = $stmt->fetchColumn();
    
    // Получаем данные с пагинацией
    $dataQuery = "
        SELECT 
            u.*,
            c.name as company_name,
            w.name as warehouse_name,
            CASE 
                WHEN u.role = 'admin' THEN 'Администратор'
                WHEN u.role = 'pc_operator' THEN 'Оператор ПК'
                WHEN u.role = 'warehouse_worker' THEN 'Работник склада'
                WHEN u.role = 'sales_manager' THEN 'Менеджер по продажам'
                ELSE u.role
            END as role_name
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        LEFT JOIN warehouses w ON u.warehouse_id = w.id
        WHERE $whereClause
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($dataQuery);
    $stmt->execute([...$bindings, $limit, $offset]);
    $employees = $stmt->fetchAll();
    
    $totalPages = ceil($total / $limit);
    
} catch (Exception $e) {
    logError('Employees list error: ' . $e->getMessage());
    $error = 'Произошла ошибка при загрузке данных';
    $employees = [];
    $total = 0;
    $totalPages = 0;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Управление пользователями системы</p>
    </div>
    <div>
        <a href="/pages/employees/create.php" class="btn btn-primary">
            <i class="bi bi-person-plus"></i>
            Добавить сотрудника
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
<div class="filters-panel mb-4">
    <form method="GET" action="" class="row g-3">
        <div class="col-md-3">
            <label for="search" class="form-label">Поиск</label>
            <input type="text" class="form-control" id="search" name="search" 
                   value="<?= e($search) ?>" placeholder="ФИО, логин, телефон...">
        </div>
        
        <div class="col-md-2">
            <label for="company_id" class="form-label">Компания</label>
            <select class="form-select" id="company_id" name="company_id">
                <option value="">Все компании</option>
                <?php foreach ($companies as $company): ?>
                <option value="<?= $company['id'] ?>" <?= $company_id == $company['id'] ? 'selected' : '' ?>>
                    <?= e($company['name']) ?>
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
                    <?= e($warehouse['name']) ?> (<?= e($warehouse['company_name']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-2">
            <label for="role" class="form-label">Роль</label>
            <select class="form-select" id="role" name="role">
                <option value="">Все роли</option>
                <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Администратор</option>
                <option value="pc_operator" <?= $role === 'pc_operator' ? 'selected' : '' ?>>Оператор ПК</option>
                <option value="warehouse_worker" <?= $role === 'warehouse_worker' ? 'selected' : '' ?>>Работник склада</option>
                <option value="sales_manager" <?= $role === 'sales_manager' ? 'selected' : '' ?>>Менеджер по продажам</option>
            </select>
        </div>
        
        <div class="col-md-2">
            <label for="status" class="form-label">Статус</label>
            <select class="form-select" id="status" name="status">
                <option value="">Все статусы</option>
                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Активные</option>
                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Заблокированные</option>
            </select>
        </div>
        
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="bi bi-search"></i>
            </button>
        </div>
    </form>
    
    <div class="row mt-2">
        <div class="col-md-6">
            <a href="/pages/employees/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-x-circle"></i>
                Сбросить фильтры
            </a>
        </div>
        <div class="col-md-6 text-end">
            <small class="text-muted">
                Найдено: <strong><?= number_format($total) ?></strong> сотрудников
            </small>
        </div>
    </div>
</div>

<!-- Список сотрудников -->
<?php if (empty($employees)): ?>
<div class="empty-state">
    <i class="bi bi-people"></i>
    <h4>Сотрудники не найдены</h4>
    <p>
        <?php if (!empty($search) || $company_id || $warehouse_id || !empty($role) || $status !== ''): ?>
            Попробуйте изменить параметры поиска или 
            <a href="/pages/employees/index.php">сбросить фильтры</a>
        <?php else: ?>
            Добавьте первого сотрудника для начала работы с системой
        <?php endif; ?>
    </p>
    
    <?php if (empty($search) && !$company_id && !$warehouse_id && empty($role) && $status === ''): ?>
    <a href="/pages/employees/create.php" class="btn btn-primary">
        <i class="bi bi-person-plus"></i>
        Добавить сотрудника
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Сотрудник</th>
                <th>Логин</th>
                <th>Роль</th>
                <th>Компания</th>
                <th>Склад</th>
                <th>Статус</th>
                <th>Последний вход</th>
                <th width="120">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $employee): ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle me-2">
                            <?= mb_strtoupper(mb_substr($employee['first_name'], 0, 1) . mb_substr($employee['last_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="fw-semibold">
                                <a href="/pages/employees/view.php?id=<?= $employee['id'] ?>" 
                                   class="text-decoration-none">
                                    <?= e(trim($employee['last_name'] . ' ' . $employee['first_name'] . ' ' . $employee['middle_name'])) ?>
                                </a>
                            </div>
                            <?php if ($employee['phone']): ?>
                            <small class="text-muted">
                                <i class="bi bi-telephone"></i>
                                <?= e($employee['phone']) ?>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                
                <td>
                    <code><?= e($employee['login']) ?></code>
                </td>
                
                <td>
                    <span class="badge role-badge role-<?= $employee['role'] ?>">
                        <?= e($employee['role_name']) ?>
                    </span>
                </td>
                
                <td>
                    <?php if ($employee['company_name']): ?>
                    <small><?= e($employee['company_name']) ?></small>
                    <?php else: ?>
                    <small class="text-muted">Не назначена</small>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($employee['warehouse_name']): ?>
                    <small><?= e($employee['warehouse_name']) ?></small>
                    <?php else: ?>
                    <small class="text-muted">Не назначен</small>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($employee['status'] == 1): ?>
                    <span class="badge bg-success">Активен</span>
                    <?php else: ?>
                    <span class="badge bg-danger">Заблокирован</span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($employee['last_login']): ?>
                    <small class="text-muted">
                        <?= formatDate($employee['last_login'], 'd.m.Y H:i') ?>
                    </small>
                    <?php else: ?>
                    <small class="text-muted">Никогда</small>
                    <?php endif; ?>
                </td>
                
                <td>
                    <div class="action-icons">
                        <a href="/pages/employees/view.php?id=<?= $employee['id'] ?>" 
                           class="text-primary" title="Просмотр">
                            <i class="bi bi-eye"></i>
                        </a>
                        
                        <a href="/pages/employees/edit.php?id=<?= $employee['id'] ?>" 
                           class="text-info" title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </a>
                        
                        <?php if ($employee['status'] == 1): ?>
                        <a href="/pages/employees/block.php?id=<?= $employee['id'] ?>" 
                           class="text-warning" title="Заблокировать"
                           data-confirm-action="Заблокировать сотрудника <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>?">
                            <i class="bi bi-lock"></i>
                        </a>
                        <?php else: ?>
                        <a href="/pages/employees/unblock.php?id=<?= $employee['id'] ?>" 
                           class="text-success" title="Разблокировать">
                            <i class="bi bi-unlock"></i>
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
<nav aria-label="Пагинация сотрудников">
    <ul class="pagination justify-content-center">
        <!-- Предыдущая страница -->
        <?php if ($page > 1): ?>
        <li class="page-item">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                <i class="bi bi-chevron-left"></i>
            </a>
        </li>
        <?php endif; ?>
        
        <!-- Страницы -->
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        if ($startPage > 1): ?>
        <li class="page-item">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
        </li>
        <?php if ($startPage > 2): ?>
        <li class="page-item disabled">
            <span class="page-link">...</span>
        </li>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
        
        <?php if ($endPage < $totalPages): ?>
        <?php if ($endPage < $totalPages - 1): ?>
        <li class="page-item disabled">
            <span class="page-link">...</span>
        </li>
        <?php endif; ?>
        <li class="page-item">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
        </li>
        <?php endif; ?>
        
        <!-- Следующая страница -->
        <?php if ($page < $totalPages): ?>
        <li class="page-item">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                <i class="bi bi-chevron-right"></i>
            </a>
        </li>
        <?php endif; ?>
    </ul>
</nav>

<div class="text-center text-muted small">
    Страница <?= $page ?> из <?= $totalPages ?> 
    (показано <?= count($employees) ?> из <?= number_format($total) ?> сотрудников)
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.avatar-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.8rem;
}

.role-badge {
    font-size: 0.75rem;
}

.role-admin { background: #dc3545 !important; }
.role-pc_operator { background: #0d6efd !important; }
.role-warehouse_worker { background: #198754 !important; }
.role-sales_manager { background: #fd7e14 !important; }

.action-icons a {
    margin: 0 0.25rem;
    font-size: 1.1rem;
}

.filters-panel {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 0.5rem;
    border: 1px solid #dee2e6;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state i {
    font-size: 4rem;
    color: #6c757d;
    margin-bottom: 1rem;
}
</style>

<script>
// Автоматический поиск при вводе
document.getElementById('search').addEventListener('input', function() {
    clearTimeout(this.searchTimeout);
    this.searchTimeout = setTimeout(() => {
        this.form.submit();
    }, 500);
});

// Обновление списка складов при выборе компании
document.getElementById('company_id').addEventListener('change', function() {
    const companyId = this.value;
    const warehouseSelect = document.getElementById('warehouse_id');
    
    // Сбрасываем выбор склада
    warehouseSelect.value = '';
    
    // Скрываем/показываем склады в зависимости от выбранной компании
    Array.from(warehouseSelect.options).forEach(option => {
        if (option.value === '') return; // Пропускаем "Все склады"
        
        if (!companyId) {
            option.style.display = 'block';
        } else {
            const optionText = option.textContent;
            const companyName = optionText.substring(optionText.indexOf('(') + 1, optionText.indexOf(')'));
            const selectedCompanyName = this.options[this.selectedIndex].textContent;
            
            option.style.display = optionText.includes('(' + selectedCompanyName + ')') ? 'block' : 'none';
        }
    });
});

// Подтверждение действий
document.addEventListener('DOMContentLoaded', function() {
    const actionLinks = document.querySelectorAll('[data-confirm-action]');
    actionLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.dataset.confirmAction;
            
            if (confirm(message)) {
                window.location.href = this.href;
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>