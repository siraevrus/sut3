<?php
/**
 * Список компаний
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$pageTitle = 'Управление компаниями';

// Параметры поиска и фильтрации
$search = trim($_GET['search'] ?? '');
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
    
    // Строим запрос с фильтрами
    $where = ['1=1'];
    $bindings = [];
    
    if (!empty($search)) {
        $where[] = "(c.name LIKE ? OR c.inn LIKE ? OR c.director LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
    }
    
    if ($status !== '') {
        $where[] = "c.status = ?";
        $bindings[] = $status;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Подсчитываем общее количество
    $countQuery = "SELECT COUNT(*) FROM companies c WHERE $whereClause";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($bindings);
    $total = $stmt->fetchColumn();
    
    // Получаем данные с пагинацией
    $dataQuery = "
        SELECT 
            c.*,
            COUNT(DISTINCT w.id) as warehouses_count,
            COUNT(DISTINCT u.id) as employees_count
        FROM companies c
        LEFT JOIN warehouses w ON c.id = w.company_id AND w.status >= 0
        LEFT JOIN users u ON c.id = u.company_id AND u.status = 1
        WHERE $whereClause
        GROUP BY c.id
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($dataQuery);
    $stmt->execute([...$bindings, $limit, $offset]);
    $companies = $stmt->fetchAll();
    
    $totalPages = ceil($total / $limit);
    
} catch (Exception $e) {
    logError('Companies list error: ' . $e->getMessage());
    $error = 'Произошла ошибка при загрузке данных';
    $companies = [];
    $total = 0;
    $totalPages = 0;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Управление компаниями и их складами</p>
    </div>
    <div>
        <a href="/pages/companies/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i>
            Добавить компанию
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
        <div class="col-md-4">
            <label for="search" class="form-label">Поиск</label>
            <input type="text" class="form-control" id="search" name="search" 
                   value="<?= e($search) ?>" placeholder="Название, ИНН, директор...">
        </div>
        
        <div class="col-md-3">
            <label for="status" class="form-label">Статус</label>
            <select class="form-select" id="status" name="status">
                <option value="">Все статусы</option>
                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Активные</option>
                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Заблокированные</option>
                <option value="-1" <?= $status === '-1' ? 'selected' : '' ?>>Архивированные</option>
            </select>
        </div>
        
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-primary me-2">
                <i class="bi bi-search"></i>
                Найти
            </button>
            <a href="/pages/companies/index.php" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i>
                Сбросить
            </a>
        </div>
        
        <div class="col-md-2 d-flex align-items-end justify-content-end">
            <small class="text-muted">
                Найдено: <strong><?= number_format($total) ?></strong> компаний
            </small>
        </div>
    </form>
</div>

<!-- Список компаний -->
<?php if (empty($companies)): ?>
<div class="empty-state">
    <i class="bi bi-building"></i>
    <h4>Компании не найдены</h4>
    <p>
        <?php if (!empty($search) || $status !== ''): ?>
            Попробуйте изменить параметры поиска или 
            <a href="/pages/companies/index.php">сбросить фильтры</a>
        <?php else: ?>
            Добавьте первую компанию, чтобы начать работу с системой
        <?php endif; ?>
    </p>
    
    <?php if (empty($search) && $status === ''): ?>
    <a href="/pages/companies/create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i>
        Добавить компанию
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Компания</th>
                <th>Контакты</th>
                <th>Склады</th>
                <th>Сотрудники</th>
                <th>Статус</th>
                <th>Создана</th>
                <th width="120">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($companies as $company): ?>
            <tr>
                <td>
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1">
                            <div class="fw-semibold">
                                <a href="/pages/companies/view.php?id=<?= $company['id'] ?>" 
                                   class="text-decoration-none">
                                    <?= e($company['name']) ?>
                                </a>
                            </div>
                            <?php if ($company['inn']): ?>
                            <small class="text-muted">ИНН: <?= e($company['inn']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                
                <td>
                    <?php if ($company['director']): ?>
                    <div class="small">
                        <i class="bi bi-person"></i>
                        <?= e($company['director']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($company['phone']): ?>
                    <div class="small text-muted">
                        <i class="bi bi-telephone"></i>
                        <?= e($company['phone']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($company['email']): ?>
                    <div class="small text-muted">
                        <i class="bi bi-envelope"></i>
                        <?= e($company['email']) ?>
                    </div>
                    <?php endif; ?>
                </td>
                
                <td>
                    <span class="badge bg-info">
                        <?= $company['warehouses_count'] ?> склад<?= ($company['warehouses_count'] % 10 == 1 && $company['warehouses_count'] % 100 != 11) ? '' : (in_array($company['warehouses_count'] % 10, [2,3,4]) && !in_array($company['warehouses_count'] % 100, [12,13,14]) ? 'а' : 'ов') ?>
                    </span>
                </td>
                
                <td>
                    <span class="badge bg-secondary">
                        <?= $company['employees_count'] ?> сотрудник<?= ($company['employees_count'] % 10 == 1 && $company['employees_count'] % 100 != 11) ? '' : (in_array($company['employees_count'] % 10, [2,3,4]) && !in_array($company['employees_count'] % 100, [12,13,14]) ? 'а' : 'ов') ?>
                    </span>
                </td>
                
                <td>
                    <?php
                    $statusClass = '';
                    $statusText = '';
                    switch ($company['status']) {
                        case 1:
                            $statusClass = 'bg-success';
                            $statusText = 'Активна';
                            break;
                        case 0:
                            $statusClass = 'bg-warning';
                            $statusText = 'Заблокирована';
                            break;
                        case -1:
                            $statusClass = 'bg-danger';
                            $statusText = 'Архивирована';
                            break;
                    }
                    ?>
                    <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                </td>
                
                <td>
                    <small class="text-muted">
                        <?= formatDate($company['created_at'], 'd.m.Y') ?>
                    </small>
                </td>
                
                <td>
                    <div class="action-icons">
                        <a href="/pages/companies/view.php?id=<?= $company['id'] ?>" 
                           class="text-primary" title="Просмотр">
                            <i class="bi bi-eye"></i>
                        </a>
                        
                        <a href="/pages/companies/edit.php?id=<?= $company['id'] ?>" 
                           class="text-info" title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </a>
                        
                        <?php if ($company['status'] != -1): ?>
                        <a href="/pages/companies/archive.php?id=<?= $company['id'] ?>" 
                           class="text-danger" title="Архивировать"
                           data-confirm-delete="Вы хотите архивировать компанию? Вместе с ней скроются все внесенные данные связанные с этой компанией">
                            <i class="bi bi-archive"></i>
                        </a>
                        <?php else: ?>
                        <a href="/pages/companies/restore.php?id=<?= $company['id'] ?>" 
                           class="text-success" title="Восстановить">
                            <i class="bi bi-arrow-clockwise"></i>
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
<nav aria-label="Пагинация компаний">
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
    (показано <?= count($companies) ?> из <?= number_format($total) ?> компаний)
</div>
<?php endif; ?>
<?php endif; ?>

<script>
// Автоматический поиск при вводе
document.getElementById('search').addEventListener('input', function() {
    clearTimeout(this.searchTimeout);
    this.searchTimeout = setTimeout(() => {
        this.form.submit();
    }, 500);
});

// Подтверждение архивирования
document.addEventListener('DOMContentLoaded', function() {
    const archiveLinks = document.querySelectorAll('[data-confirm-delete]');
    archiveLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.dataset.confirmDelete;
            
            if (confirm(message + '\n\nДа / Отмена')) {
                window.location.href = this.href;
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>