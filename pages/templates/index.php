<?php
/**
 * Список шаблонов товаров
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('product_templates');

$pageTitle = 'Характеристики товара';

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
        $where[] = "(pt.name LIKE ? OR pt.description LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $bindings[] = $searchTerm;
        $bindings[] = $searchTerm;
    }
    
    if ($status !== '') {
        $where[] = "pt.status = ?";
        $bindings[] = $status;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Подсчитываем общее количество
    $countQuery = "SELECT COUNT(*) FROM product_templates pt WHERE $whereClause";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($bindings);
    $total = $stmt->fetchColumn();
    
    // Получаем данные с пагинацией
    $dataQuery = "
        SELECT 
            pt.*,
            u.first_name as creator_name,
            u.last_name as creator_lastname,
            (SELECT COUNT(*) FROM template_attributes ta WHERE ta.template_id = pt.id) as attributes_count,
            (SELECT COUNT(*) FROM products p WHERE p.template_id = pt.id) as products_count
        FROM product_templates pt
        LEFT JOIN users u ON pt.created_by = u.id
        WHERE $whereClause
        ORDER BY pt.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($dataQuery);
    $stmt->execute([...$bindings, $limit, $offset]);
    $templates = $stmt->fetchAll();
    
    $totalPages = ceil($total / $limit);
    
} catch (Exception $e) {
    logError('Templates list error: ' . $e->getMessage());
    $error = 'Произошла ошибка при загрузке данных';
    $templates = [];
    $total = 0;
    $totalPages = 0;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Управление шаблонами товаров с характеристиками и формулами</p>
    </div>
    <div>
        <a href="/pages/templates/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i>
            Создать шаблон
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
                   value="<?= e($search) ?>" placeholder="Название или описание...">
        </div>
        
        <div class="col-md-3">
            <label for="status" class="form-label">Статус</label>
            <select class="form-select" id="status" name="status">
                <option value="">Все статусы</option>
                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Активные</option>
                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Неактивные</option>
            </select>
        </div>
        
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="bi bi-search"></i>
            </button>
        </div>
        
        <div class="col-md-3 d-flex align-items-end">
            <a href="/pages/templates/index.php" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i>
                Сбросить
            </a>
        </div>
    </form>
    
    <div class="row mt-2">
        <div class="col-md-6">
            <small class="text-muted">
                Найдено: <strong><?= number_format($total) ?></strong> шаблонов
            </small>
        </div>
    </div>
</div>

<!-- Список шаблонов -->
<?php if (empty($templates)): ?>
<div class="empty-state">
    <i class="bi bi-diagram-3"></i>
    <h4>Шаблоны не найдены</h4>
    <p>
        <?php if (!empty($search) || $status !== ''): ?>
            Попробуйте изменить параметры поиска или 
            <a href="/pages/templates/index.php">сбросить фильтры</a>
        <?php else: ?>
            Создайте первый шаблон товара для начала работы
        <?php endif; ?>
    </p>
    
    <?php if (empty($search) && $status === ''): ?>
    <a href="/pages/templates/create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i>
        Создать шаблон
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="row">
    <?php foreach ($templates as $template): ?>
    <div class="col-lg-6 col-xl-4 mb-4">
        <div class="card template-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">
                    <a href="/pages/templates/view.php?id=<?= $template['id'] ?>" 
                       class="text-decoration-none">
                        <?= e($template['name']) ?>
                    </a>
                </h6>
                <div class="template-status">
                    <?php if ($template['status'] == 1): ?>
                    <span class="badge bg-success">Активен</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Неактивен</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if ($template['description']): ?>
                <p class="card-text text-muted small mb-3">
                    <?= e(mb_strimwidth($template['description'], 0, 120, '...')) ?>
                </p>
                <?php endif; ?>
                
                <div class="template-stats mb-3">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="stat-number"><?= $template['attributes_count'] ?></div>
                            <div class="stat-label">Характеристик</div>
                        </div>
                        <div class="col-4">
                            <div class="stat-number"><?= $template['products_count'] ?></div>
                            <div class="stat-label">Товаров</div>
                        </div>
                        <div class="col-4">
                            <div class="stat-number">
                                <?php if ($template['formula']): ?>
                                <i class="bi bi-calculator text-success"></i>
                                <?php else: ?>
                                <i class="bi bi-dash text-muted"></i>
                                <?php endif; ?>
                            </div>
                            <div class="stat-label">Формула</div>
                        </div>
                    </div>
                </div>
                
                <?php if ($template['formula']): ?>
                <div class="formula-preview mb-3">
                    <small class="text-muted">Формула:</small>
                    <code class="d-block"><?= e(mb_strimwidth($template['formula'], 0, 40, '...')) ?></code>
                </div>
                <?php endif; ?>
                
                <div class="template-meta">
                    <small class="text-muted">
                        <i class="bi bi-person"></i>
                        <?= e($template['creator_name'] . ' ' . $template['creator_lastname']) ?>
                        <br>
                        <i class="bi bi-calendar"></i>
                        <?= formatDate($template['created_at'], 'd.m.Y') ?>
                    </small>
                </div>
            </div>
            <div class="card-footer bg-transparent">
                <div class="btn-group w-100" role="group">
                    <a href="/pages/templates/view.php?id=<?= $template['id'] ?>" 
                       class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-eye"></i>
                        Просмотр
                    </a>
                    <a href="/pages/templates/edit.php?id=<?= $template['id'] ?>" 
                       class="btn btn-outline-info btn-sm">
                        <i class="bi bi-pencil"></i>
                        Редактировать
                    </a>
                    <?php if ($template['products_count'] == 0): ?>
                    <a href="/pages/templates/delete.php?id=<?= $template['id'] ?>" 
                       class="btn btn-outline-danger btn-sm"
                       data-confirm-action="Удалить шаблон &quot;<?= e($template['name']) ?>&quot;?">
                        <i class="bi bi-trash"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Пагинация -->
<?php if ($totalPages > 1): ?>
<nav aria-label="Пагинация шаблонов">
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
    (показано <?= count($templates) ?> из <?= number_format($total) ?> шаблонов)
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.template-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.template-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.template-stats .stat-number {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--primary-color);
}

.template-stats .stat-label {
    font-size: 0.75rem;
    color: #6c757d;
}

.formula-preview {
    background: #f8f9fa;
    border-left: 3px solid var(--primary-color);
    padding: 0.5rem;
    border-radius: 0.25rem;
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