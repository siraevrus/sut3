<?php
/**
 * Профиль пользователя
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../config/config.php';

requireAuth();

$user = getCurrentUser();
$pageTitle = 'Профиль пользователя';

$success = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $error = 'Ошибка безопасности';
    } else {
        switch ($action) {
            case 'change_password':
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    $error = 'Заполните все поля';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'Новые пароли не совпадают';
                } elseif (strlen($newPassword) < 6) {
                    $error = 'Пароль должен содержать минимум 6 символов';
                } else {
                    try {
                        $pdo = getDBConnection();
                        
                        // Проверяем текущий пароль
                        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        $storedPassword = $stmt->fetchColumn();
                        
                        if (!verifyPassword($currentPassword, $storedPassword)) {
                            $error = 'Неверный текущий пароль';
                        } else {
                            // Обновляем пароль
                            $hashedPassword = hashPassword($newPassword);
                            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $stmt->execute([$hashedPassword, $user['id']]);
                            
                            $success = 'Пароль успешно изменен';
                            
                            // Логируем изменение пароля
                            logError('Password changed', ['user_id' => $user['id']]);
                        }
                        
                    } catch (Exception $e) {
                        logError('Change password error: ' . $e->getMessage());
                        $error = 'Произошла ошибка при изменении пароля';
                    }
                }
                break;
                
            case 'terminate_sessions':
                $count = SessionManager::terminateOtherSessions($user['id']);
                $success = "Завершено сессий: $count";
                break;
                
            case 'terminate_session':
                $sessionId = $_POST['session_id'] ?? '';
                if (SessionManager::terminateSession($sessionId, $user['id'])) {
                    $success = 'Сессия завершена';
                } else {
                    $error = 'Не удалось завершить сессию';
                }
                break;
        }
    }
}

// Получаем активные сессии
$sessions = SessionManager::getUserSessions($user['id']);

// Получаем детальную информацию о пользователе
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT u.*, c.name as company_name, w.name as warehouse_name
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        LEFT JOIN warehouses w ON u.warehouse_id = w.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user['id']]);
    $userDetails = $stmt->fetch();
} catch (Exception $e) {
    logError('Get user details error: ' . $e->getMessage());
    $userDetails = $user;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-md-8">
        <!-- Информация о пользователе -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person-circle"></i>
                    Информация о пользователе
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="detail-row">
                            <div class="detail-label">ФИО:</div>
                            <div class="detail-value">
                                <?= e(trim($userDetails['last_name'] . ' ' . $userDetails['first_name'] . ' ' . $userDetails['middle_name'])) ?>
                            </div>
                        </div>
                        
                        <div class="detail-row">
                            <div class="detail-label">Логин:</div>
                            <div class="detail-value"><?= e($userDetails['login']) ?></div>
                        </div>
                        
                        <div class="detail-row">
                            <div class="detail-label">Роль:</div>
                            <div class="detail-value">
                                <?php
                                $roleNames = [
                                    'admin' => 'Администратор',
                                    'pc_operator' => 'Оператор ПК',
                                    'warehouse_worker' => 'Работник склада',
                                    'sales_manager' => 'Менеджер по продажам'
                                ];
                                echo e($roleNames[$userDetails['role']] ?? $userDetails['role']);
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <?php if ($userDetails['company_name']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Компания:</div>
                            <div class="detail-value"><?= e($userDetails['company_name']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($userDetails['warehouse_name']): ?>
                        <div class="detail-row">
                            <div class="detail-label">Склад:</div>
                            <div class="detail-value"><?= e($userDetails['warehouse_name']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-row">
                            <div class="detail-label">Телефон:</div>
                            <div class="detail-value"><?= e($userDetails['phone'] ?? 'Не указан') ?></div>
                        </div>
                        
                        <div class="detail-row">
                            <div class="detail-label">Последний вход:</div>
                            <div class="detail-value">
                                <?= $userDetails['last_login'] ? formatDate($userDetails['last_login']) : 'Никогда' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Смена пароля -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-key"></i>
                    Смена пароля
                </h5>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i> <?= e($success) ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> <?= e($error) ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Текущий пароль</label>
                                <input type="password" class="form-control" id="current_password" 
                                       name="current_password" required>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="new_password" class="form-label">Новый пароль</label>
                                <input type="password" class="form-control" id="new_password" 
                                       name="new_password" required minlength="6">
                                <div class="form-text">Минимум 6 символов</div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Подтвердите пароль</label>
                                <input type="password" class="form-control" id="confirm_password" 
                                       name="confirm_password" required minlength="6">
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-key"></i>
                        Изменить пароль
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Активные сессии -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-shield-check"></i>
                    Активные сессии
                </h5>
                
                <?php if (count($sessions) > 1): ?>
                <form method="POST" action="" class="d-inline">
                    <input type="hidden" name="action" value="terminate_sessions">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" 
                            onclick="return confirm('Завершить все остальные сессии?')">
                        <i class="bi bi-x-circle"></i>
                        Завершить все
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($sessions)): ?>
                <div class="text-muted text-center py-3">
                    <i class="bi bi-shield-x" style="font-size: 2rem;"></i>
                    <div>Нет активных сессий</div>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($sessions as $session): ?>
                    <div class="list-group-item px-0 <?= $session['is_current'] ? 'bg-light' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center mb-1">
                                    <?php if ($session['is_current']): ?>
                                    <span class="badge bg-success me-2">Текущая</span>
                                    <?php endif; ?>
                                    
                                    <small class="text-muted">
                                        IP: <?= e($session['ip_address']) ?>
                                    </small>
                                </div>
                                
                                <div class="small text-muted mb-1">
                                    <?php
                                    // Упрощенное отображение User Agent
                                    $ua = $session['user_agent'];
                                    if (strpos($ua, 'Chrome') !== false) {
                                        echo '<i class="bi bi-browser-chrome"></i> Chrome';
                                    } elseif (strpos($ua, 'Firefox') !== false) {
                                        echo '<i class="bi bi-browser-firefox"></i> Firefox';
                                    } elseif (strpos($ua, 'Safari') !== false) {
                                        echo '<i class="bi bi-browser-safari"></i> Safari';
                                    } else {
                                        echo '<i class="bi bi-globe"></i> ' . e(substr($ua, 0, 30)) . '...';
                                    }
                                    ?>
                                </div>
                                
                                <div class="small text-muted">
                                    Активность: <?= formatDate($session['last_activity']) ?>
                                </div>
                                
                                <div class="small text-muted">
                                    Истекает: <?= formatDate($session['expires_at']) ?>
                                </div>
                            </div>
                            
                            <?php if (!$session['is_current']): ?>
                            <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="action" value="terminate_session">
                                <input type="hidden" name="session_id" value="<?= e($session['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" 
                                        onclick="return confirm('Завершить эту сессию?')">
                                    <i class="bi bi-x"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Валидация формы смены пароля
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form[action=""]');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    function validatePasswords() {
        if (newPassword.value && confirmPassword.value) {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Пароли не совпадают');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
    }
    
    newPassword.addEventListener('input', validatePasswords);
    confirmPassword.addEventListener('input', validatePasswords);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>