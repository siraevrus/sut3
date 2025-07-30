<?php
/**
 * Просмотр компании
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$companyId = (int)($_GET['id'] ?? 0);

if (!$companyId) {
    $_SESSION['error_message'] = 'Компания не найдена';
    header('Location: /pages/companies/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о компании
    $stmt = $pdo->prepare("
        SELECT c.*, 
               COUNT(DISTINCT w.id) as warehouses_count,
               COUNT(DISTINCT u.id) as employees_count
        FROM companies c
        LEFT JOIN warehouses w ON c.id = w.company_id AND w.status >= 0
        LEFT JOIN users u ON c.id = u.company_id AND u.status = 1
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if (!$company) {
        $_SESSION['error_message'] = 'Компания не найдена';
        header('Location: /pages/companies/index.php');
        exit;
    }
    
    // Получаем склады компании
    $stmt = $pdo->prepare("
        SELECT w.*, 
               COUNT(DISTINCT u.id) as employees_count
        FROM warehouses w
        LEFT JOIN users u ON w.id = u.warehouse_id AND u.status = 1
        WHERE w.company_id = ? AND w.status >= 0
        GROUP BY w.id
        ORDER BY w.created_at DESC
    ");
    $stmt->execute([$companyId]);
    $warehouses = $stmt->fetchAll();
    
    // Получаем сотрудников компании
    $stmt = $pdo->prepare("
        SELECT u.*, w.name as warehouse_name
        FROM users u
        LEFT JOIN warehouses w ON u.warehouse_id = w.id
        WHERE u.company_id = ? AND u.status >= 0
        ORDER BY u.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$companyId]);
    $employees = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError('Company view error: ' . $e->getMessage(), ['company_id' => $companyId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/companies/index.php');
    exit;
}

$pageTitle = $company['name'];

// Обработка сообщений
$success = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/companies/index.php">Компании</a>
                </li>
                <li class="breadcrumb-item active"><?= e($company['name']) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <h1 class="h3 mb-0"><?= e($company['name']) ?></h1>
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
        </div>
    </div>
    <div>
        <a href="/pages/companies/edit.php?id=<?= $company['id'] ?>" class="btn btn-primary">
            <i class="bi bi-pencil"></i>
            Редактировать
        </a>
        <a href="/pages/companies/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад
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

<div class="row">
    <div class="col-lg-8">
        <!-- Информация о компании -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-building"></i>
                    Информация о компании
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <?php if ($company['director']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Генеральный директор:</div>
                            <div class="detail-value"><?= e($company['director']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['legal_address']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Юридический адрес:</div>
                            <div class="detail-value"><?= nl2br(e($company['legal_address'])) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['postal_address']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Почтовый адрес:</div>
                            <div class="detail-value"><?= nl2br(e($company['postal_address'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-6">
                        <?php if ($company['phone']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Телефон:</div>
                            <div class="detail-value">
                                <a href="tel:<?= e($company['phone']) ?>"><?= e($company['phone']) ?></a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['email']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Email:</div>
                            <div class="detail-value">
                                <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-row">
                            <div class="detail-label">Дата создания:</div>
                            <div class="detail-value"><?= formatDate($company['created_at']) ?></div>
                        </div>
                        
                        <div class="detail-row">
                            <div class="detail-label">Последнее обновление:</div>
                            <div class="detail-value"><?= formatDate($company['updated_at']) ?></div>
                        </div>
                    </div>
                </div>
                
                <?php if ($company['inn'] || $company['kpp'] || $company['ogrn']): ?>
                <hr>
                <h6>Реквизиты</h6>
                <div class="row">
                    <div class="col-md-6">
                        <?php if ($company['inn']): ?>
                        <div class="detail-row">
                            <div class="detail-label">ИНН:</div>
                            <div class="detail-value"><?= e($company['inn']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['kpp']): ?>
                        <div class="detail-row">
                            <div class="detail-label">КПП:</div>
                            <div class="detail-value"><?= e($company['kpp']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['ogrn']): ?>
                        <div class="detail-row">
                            <div class="detail-label">ОГРН:</div>
                            <div class="detail-value"><?= e($company['ogrn']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-6">
                        <?php if ($company['bank']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Банк:</div>
                            <div class="detail-value"><?= e($company['bank']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['account']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Расчетный счет:</div>
                            <div class="detail-value"><?= e($company['account']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['correspondent_account']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Корр. счет:</div>
                            <div class="detail-value"><?= e($company['correspondent_account']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($company['bik']): ?>
                        <div class="detail-row">
                            <div class="detail-label">БИК:</div>
                            <div class="detail-value"><?= e($company['bik']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Склады -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-warehouse"></i>
                    Склады (<?= count($warehouses) ?>)
                </h5>
                <a href="/pages/companies/warehouse_create.php?company_id=<?= $company['id'] ?>" 
                   class="btn btn-sm btn-primary">
                    <i class="bi bi-plus"></i>
                    Добавить склад
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($warehouses)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-warehouse text-muted" style="font-size: 3rem;"></i>
                    <h6 class="text-muted mt-2">Склады не добавлены</h6>
                    <p class="text-muted mb-3">Добавьте первый склад для начала работы</p>
                    <a href="/pages/companies/warehouse_create.php?company_id=<?= $company['id'] ?>" 
                       class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Добавить склад
                    </a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Название</th>
                                <th>Адрес</th>
                                <th>Сотрудники</th>
                                <th>Статус</th>
                                <th width="100">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($warehouses as $warehouse): ?>
                            <tr>
                                <td>
                                    <strong><?= e($warehouse['name']) ?></strong>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= e($warehouse['address'] ?: 'Не указан') ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= $warehouse['employees_count'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($warehouse['status'] == 1): ?>
                                    <span class="badge bg-success">Активен</span>
                                    <?php else: ?>
                                    <span class="badge bg-warning">Неактивен</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-icons">
                                        <a href="/pages/companies/warehouse_edit.php?id=<?= $warehouse['id'] ?>" 
                                           class="text-info" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="/pages/companies/warehouse_delete.php?id=<?= $warehouse['id'] ?>" 
                                           class="text-danger" title="Удалить"
                                           data-confirm-delete="Удалить склад '<?= e($warehouse['name']) ?>'?">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Статистика -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-graph-up"></i>
                    Статистика
                </h6>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6">
                        <div class="h4 text-primary mb-0"><?= $company['warehouses_count'] ?></div>
                        <small class="text-muted">Склады</small>
                    </div>
                    <div class="col-6">
                        <div class="h4 text-success mb-0"><?= $company['employees_count'] ?></div>
                        <small class="text-muted">Сотрудники</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Сотрудники -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">
                    <i class="bi bi-people"></i>
                    Сотрудники
                </h6>
                <a href="/pages/employees/index.php?company_id=<?= $company['id'] ?>" 
                   class="btn btn-sm btn-outline-primary">
                    Все
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($employees)): ?>
                <div class="text-center py-3">
                    <i class="bi bi-people text-muted" style="font-size: 2rem;"></i>
                    <div class="text-muted mt-2">Сотрудники не назначены</div>
                </div>
                <?php else: ?>
                <?php foreach ($employees as $employee): ?>
                <div class="d-flex align-items-center mb-2">
                    <div class="flex-grow-1">
                        <div class="small fw-semibold">
                            <?= e(trim($employee['last_name'] . ' ' . $employee['first_name'] . ' ' . $employee['middle_name'])) ?>
                        </div>
                        <div class="small text-muted">
                            <?= e($employee['role']) ?>
                            <?php if ($employee['warehouse_name']): ?>
                            • <?= e($employee['warehouse_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($employee['status'] == 1): ?>
                    <small class="badge bg-success">Активен</small>
                    <?php else: ?>
                    <small class="badge bg-warning">Заблокирован</small>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                
                <?php if (count($employees) >= 10): ?>
                <div class="text-center mt-3">
                    <a href="/pages/employees/index.php?company_id=<?= $company['id'] ?>" 
                       class="btn btn-sm btn-outline-primary">
                        Показать всех
                    </a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Подтверждение удаления
document.addEventListener('DOMContentLoaded', function() {
    const deleteLinks = document.querySelectorAll('[data-confirm-delete]');
    deleteLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.dataset.confirmDelete;
            
            if (confirm(message)) {
                window.location.href = this.href;
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>