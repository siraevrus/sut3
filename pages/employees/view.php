<?php
/**
 * Просмотр сотрудника
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('employees');

$employeeId = (int)($_GET['id'] ?? 0);

if (!$employeeId) {
    $_SESSION['error_message'] = 'Сотрудник не найден';
    header('Location: /pages/employees/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о сотруднике
    $stmt = $pdo->prepare("
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
        WHERE u.id = ?
    ");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        $_SESSION['error_message'] = 'Сотрудник не найден';
        header('Location: /pages/employees/index.php');
        exit;
    }
    
    // Получаем активные сессии пользователя
    $stmt = $pdo->prepare("
        SELECT 
            session_id,
            ip_address,
            user_agent,
            last_activity,
            created_at
        FROM user_sessions 
        WHERE user_id = ? 
        ORDER BY last_activity DESC
        LIMIT 10
    ");
    $stmt->execute([$employeeId]);
    $sessions = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError('Employee view error: ' . $e->getMessage(), ['employee_id' => $employeeId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/employees/index.php');
    exit;
}

$pageTitle = trim($employee['last_name'] . ' ' . $employee['first_name'] . ' ' . $employee['middle_name']);

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
                    <a href="/pages/employees/index.php">Сотрудники</a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
            <?php if ($employee['status'] == 1): ?>
            <span class="badge bg-success">Активен</span>
            <?php else: ?>
            <span class="badge bg-danger">Заблокирован</span>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <a href="/pages/employees/edit.php?id=<?= $employee['id'] ?>" class="btn btn-primary">
            <i class="bi bi-pencil"></i>
            Редактировать
        </a>
        <a href="/pages/employees/index.php" class="btn btn-outline-secondary">
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
        <!-- Основная информация -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person"></i>
                    Информация о сотруднике
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="detail-row">
                            <div class="detail-label">ФИО:</div>
                            <div class="detail-value"><?= e($pageTitle) ?></div>
                        </div>
                        
                        <div class="detail-row">
                            <div class="detail-label">Логин:</div>
                            <div class="detail-value">
                                <code><?= e($employee['login']) ?></code>
                            </div>
                        </div>
                        
                        <?php if ($employee['phone']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Телефон:</div>
                            <div class="detail-value">
                                <a href="tel:<?= e($employee['phone']) ?>"><?= e($employee['phone']) ?></a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-row">
                            <div class="detail-label">Роль:</div>
                            <div class="detail-value">
                                <span class="badge role-badge role-<?= $employee['role'] ?>">
                                    <?= e($employee['role_name']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <?php if ($employee['company_name']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Компания:</div>
                            <div class="detail-value">
                                <a href="/pages/companies/view.php?id=<?= $employee['company_id'] ?>">
                                    <?= e($employee['company_name']) ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($employee['warehouse_name']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Склад:</div>
                            <div class="detail-value"><?= e($employee['warehouse_name']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-row">
                            <div class="detail-label">Дата создания:</div>
                            <div class="detail-value"><?= formatDate($employee['created_at']) ?></div>
                        </div>
                        
                        <div class="detail-row">
                            <div class="detail-label">Последнее обновление:</div>
                            <div class="detail-value"><?= formatDate($employee['updated_at']) ?></div>
                        </div>
                        
                        <?php if ($employee['last_login']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Последний вход:</div>
                            <div class="detail-value"><?= formatDate($employee['last_login']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($employee['blocked_at']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Заблокирован:</div>
                            <div class="detail-value text-danger"><?= formatDate($employee['blocked_at']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Активные сессии -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-activity"></i>
                    Активные сессии (<?= count($sessions) ?>)
                </h5>
                <?php if (!empty($sessions)): ?>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="terminateAllSessions()">
                    <i class="bi bi-x-circle"></i>
                    Завершить все сессии
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($sessions)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-activity text-muted" style="font-size: 3rem;"></i>
                    <h6 class="text-muted mt-2">Активных сессий нет</h6>
                    <p class="text-muted">Пользователь не входил в систему или все сессии завершены</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>IP адрес</th>
                                <th>Браузер/Устройство</th>
                                <th>Создана</th>
                                <th>Последняя активность</th>
                                <th width="80">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session): ?>
                            <tr>
                                <td>
                                    <code><?= e($session['ip_address']) ?></code>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= e(parseUserAgent($session['user_agent'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <small><?= formatDate($session['created_at'], 'd.m.Y H:i') ?></small>
                                </td>
                                <td>
                                    <small><?= formatDate($session['last_activity'], 'd.m.Y H:i') ?></small>
                                </td>
                                <td>
                                    <a href="/pages/employees/terminate_session.php?id=<?= $employee['id'] ?>&session=<?= e($session['session_id']) ?>" 
                                       class="text-danger" 
                                       title="Завершить сессию"
                                       data-confirm-action="Завершить эту сессию?">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
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
        <!-- Быстрые действия -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-lightning"></i>
                    Быстрые действия
                </h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/pages/employees/edit.php?id=<?= $employee['id'] ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil"></i>
                        Редактировать данные
                    </a>
                    
                    <a href="/pages/employees/reset_password.php?id=<?= $employee['id'] ?>" 
                       class="btn btn-outline-warning btn-sm"
                       data-confirm-action="Сбросить пароль для <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>?">
                        <i class="bi bi-key"></i>
                        Сбросить пароль
                    </a>
                    
                    <?php if ($employee['status'] == 1): ?>
                    <a href="/pages/employees/block.php?id=<?= $employee['id'] ?>" 
                       class="btn btn-outline-danger btn-sm"
                       data-confirm-action="Заблокировать сотрудника?">
                        <i class="bi bi-lock"></i>
                        Заблокировать
                    </a>
                    <?php else: ?>
                    <a href="/pages/employees/unblock.php?id=<?= $employee['id'] ?>" 
                       class="btn btn-outline-success btn-sm">
                        <i class="bi bi-unlock"></i>
                        Разблокировать
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Информация о роли -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-shield-check"></i>
                    Права доступа
                </h6>
            </div>
            <div class="card-body">
                <?php
                $rolePermissions = [
                    'admin' => [
                        'Полный доступ ко всем разделам',
                        'Управление пользователями',
                        'Управление компаниями',
                        'Настройки системы'
                    ],
                    'pc_operator' => [
                        'Работа с товарами',
                        'Управление остатками',
                        'Обработка запросов',
                        'Просмотр отчетов'
                    ],
                    'warehouse_worker' => [
                        'Приемка товаров',
                        'Отгрузка товаров',
                        'Работа с остатками склада',
                        'Создание запросов'
                    ],
                    'sales_manager' => [
                        'Реализация товаров',
                        'Работа с клиентами',
                        'Обработка запросов',
                        'Просмотр продаж'
                    ]
                ];
                
                $permissions = $rolePermissions[$employee['role']] ?? [];
                ?>
                
                <div class="role-permissions">
                    <div class="mb-2">
                        <span class="badge role-badge role-<?= $employee['role'] ?>">
                            <?= e($employee['role_name']) ?>
                        </span>
                    </div>
                    
                    <?php if (!empty($permissions)): ?>
                    <ul class="list-unstyled small">
                        <?php foreach ($permissions as $permission): ?>
                        <li class="mb-1">
                            <i class="bi bi-check-circle text-success"></i>
                            <?= e($permission) ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.detail-row {
    display: flex;
    margin-bottom: 0.75rem;
}

.detail-label {
    font-weight: 600;
    min-width: 140px;
    color: #6c757d;
}

.detail-value {
    flex: 1;
}

.role-badge {
    font-size: 0.75rem;
}

.role-admin { background: #dc3545 !important; }
.role-pc_operator { background: #0d6efd !important; }
.role-warehouse_worker { background: #198754 !important; }
.role-sales_manager { background: #fd7e14 !important; }

.role-permissions .list-unstyled li {
    padding: 0.25rem 0;
}
</style>

<script>
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

// Завершение всех сессий
function terminateAllSessions() {
    if (confirm('Завершить все активные сессии пользователя? Он будет принудительно выведен из системы.')) {
        window.location.href = '/pages/employees/terminate_all_sessions.php?id=<?= $employee['id'] ?>';
    }
}
</script>

<?php
/**
 * Упрощенный парсер User Agent
 */
function parseUserAgent($userAgent) {
    if (empty($userAgent)) return 'Неизвестно';
    
    // Определяем браузер
    if (strpos($userAgent, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (strpos($userAgent, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (strpos($userAgent, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (strpos($userAgent, 'Edge') !== false) {
        $browser = 'Edge';
    } else {
        $browser = 'Другой';
    }
    
    // Определяем ОС
    if (strpos($userAgent, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (strpos($userAgent, 'Mac') !== false) {
        $os = 'macOS';
    } elseif (strpos($userAgent, 'Linux') !== false) {
        $os = 'Linux';
    } elseif (strpos($userAgent, 'Android') !== false) {
        $os = 'Android';
    } elseif (strpos($userAgent, 'iOS') !== false) {
        $os = 'iOS';
    } else {
        $os = 'Другая';
    }
    
    return $browser . ' на ' . $os;
}

require_once __DIR__ . '/../../includes/footer.php';
?>