<?php
/**
 * Сброс пароля сотрудника
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

$errors = [];
$success = '';
$newPassword = '';

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о сотруднике
    $stmt = $pdo->prepare("SELECT id, login, first_name, last_name, status FROM users WHERE id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        $_SESSION['error_message'] = 'Сотрудник не найден';
        header('Location: /pages/employees/index.php');
        exit;
    }
    
    // Проверяем, что не сбрасываем пароль самому себе
    $currentUser = getCurrentUser();
    if ($currentUser['id'] == $employeeId) {
        $_SESSION['error_message'] = 'Нельзя сбросить пароль самому себе. Используйте функцию смены пароля в профиле.';
        header('Location: /pages/employees/view.php?id=' . $employeeId);
        exit;
    }
    
} catch (Exception $e) {
    logError('Reset password load error: ' . $e->getMessage(), ['employee_id' => $employeeId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/employees/index.php');
    exit;
}

// Обработка формы сброса пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } elseif ($action === 'reset_password') {
        $passwordType = $_POST['password_type'] ?? 'generate';
        $customPassword = trim($_POST['custom_password'] ?? '');
        $terminateSessions = isset($_POST['terminate_sessions']);
        
        if ($passwordType === 'custom') {
            if (empty($customPassword)) {
                $errors['custom_password'] = 'Необходимо указать новый пароль';
            } elseif (strlen($customPassword) < 6) {
                $errors['custom_password'] = 'Пароль должен содержать минимум 6 символов';
            } else {
                $newPassword = $customPassword;
            }
        } else {
            // Генерируем случайный пароль
            $newPassword = generateRandomPassword(10);
        }
        
        if (empty($errors) && !empty($newPassword)) {
            try {
                // Хешируем новый пароль
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Обновляем пароль в базе данных
                $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashedPassword, $employeeId]);
                
                // Завершаем активные сессии если выбрано
                if ($terminateSessions) {
                    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                    $stmt->execute([$employeeId]);
                }
                
                // Логируем сброс пароля
                logError('Password reset by admin', [
                    'employee_id' => $employeeId,
                    'employee_login' => $employee['login'],
                    'reset_by' => $currentUser['id'],
                    'terminate_sessions' => $terminateSessions,
                    'password_type' => $passwordType
                ]);
                
                $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
                $success = "Пароль для сотрудника \"{$fullName}\" успешно сброшен.";
                if ($terminateSessions) {
                    $success .= " Все активные сессии завершены.";
                }
                
            } catch (Exception $e) {
                logError('Reset password error: ' . $e->getMessage(), [
                    'employee_id' => $employeeId,
                    'password_type' => $passwordType
                ]);
                $errors['general'] = 'Произошла ошибка при сбросе пароля';
            }
        }
    }
}

/**
 * Генерация случайного пароля
 */
function generateRandomPassword($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

$pageTitle = 'Сброс пароля';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/employees/index.php">Сотрудники</a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/pages/employees/view.php?id=<?= $employeeId ?>">
                        <?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?>
                    </a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">
            Сотрудник: <?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?> 
            (<?= e($employee['login']) ?>)
        </p>
    </div>
    <div>
        <a href="/pages/employees/view.php?id=<?= $employeeId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад
        </a>
    </div>
</div>

<?php if (!empty($errors['general'])): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle"></i>
    <?= e($errors['general']) ?>
</div>
<?php endif; ?>

<?php if (!empty($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle"></i>
    <?= e($success) ?>
    
    <?php if (!empty($newPassword)): ?>
    <hr>
    <div class="d-flex align-items-center justify-content-between bg-light p-3 rounded mt-3">
        <div>
            <strong>Новый пароль:</strong> 
            <code id="newPassword" class="fs-5"><?= e($newPassword) ?></code>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyPassword()">
            <i class="bi bi-clipboard"></i>
            Скопировать
        </button>
    </div>
    <div class="text-muted small mt-2">
        <i class="bi bi-info-circle"></i>
        Обязательно сохраните или передайте этот пароль сотруднику. После обновления страницы он больше не будет отображаться.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-key"></i>
                    Сброс пароля
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($success)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Внимание!</strong> Эта операция необратима. После сброса пароля старый пароль перестанет работать.
                </div>
                
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="reset_password">
                    
                    <div class="mb-4">
                        <label class="form-label">Тип нового пароля</label>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="password_type" id="generate" value="generate" checked>
                            <label class="form-check-label" for="generate">
                                <strong>Сгенерировать автоматически</strong>
                                <div class="text-muted small">Система создаст безопасный пароль из 10 символов</div>
                            </label>
                        </div>
                        
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="radio" name="password_type" id="custom" value="custom">
                            <label class="form-check-label" for="custom">
                                <strong>Задать вручную</strong>
                                <div class="text-muted small">Укажите собственный пароль для сотрудника</div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="customPasswordField" style="display: none;">
                        <label for="custom_password" class="form-label">
                            Новый пароль <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control <?= isset($errors['custom_password']) ? 'is-invalid' : '' ?>" 
                                   id="custom_password" 
                                   name="custom_password" 
                                   placeholder="Минимум 6 символов">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility()">
                                <i class="bi bi-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['custom_password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['custom_password']) ?></div>
                        <?php endif; ?>
                        <div class="form-text">Рекомендуется использовать комбинацию букв, цифр и специальных символов</div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="terminate_sessions" name="terminate_sessions" checked>
                            <label class="form-check-label" for="terminate_sessions">
                                <strong>Завершить все активные сессии</strong>
                                <div class="text-muted small">Пользователь будет принудительно выведен из всех устройств</div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-key"></i>
                            Сбросить пароль
                        </button>
                        <a href="/pages/employees/view.php?id=<?= $employeeId ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i>
                            Отменить
                        </a>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    <h4 class="text-success mt-3">Пароль успешно сброшен</h4>
                    <p class="text-muted">Новый пароль был установлен для сотрудника</p>
                    
                    <div class="d-flex gap-2 justify-content-center mt-4">
                        <a href="/pages/employees/view.php?id=<?= $employeeId ?>" class="btn btn-primary">
                            <i class="bi bi-person"></i>
                            Перейти к профилю
                        </a>
                        <a href="/pages/employees/index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-people"></i>
                            Список сотрудников
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-info-circle"></i>
                    Информация о сотруднике
                </h6>
            </div>
            <div class="card-body">
                <div class="detail-row">
                    <div class="detail-label">ФИО:</div>
                    <div class="detail-value"><?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Логин:</div>
                    <div class="detail-value"><code><?= e($employee['login']) ?></code></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Статус:</div>
                    <div class="detail-value">
                        <?php if ($employee['status'] == 1): ?>
                        <span class="badge bg-success">Активен</span>
                        <?php else: ?>
                        <span class="badge bg-danger">Заблокирован</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <hr>
                
                <h6>Рекомендации по безопасности</h6>
                <ul class="list-unstyled small text-muted">
                    <li class="mb-2">
                        <i class="bi bi-shield-check text-success"></i>
                        Используйте сложные пароли (8+ символов)
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-eye-slash text-info"></i>
                        Не передавайте пароли по незащищенным каналам
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-arrow-clockwise text-warning"></i>
                        Рекомендуйте сменить пароль при первом входе
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-journal-text text-primary"></i>
                        Все операции с паролями логируются
                    </li>
                </ul>
            </div>
        </div>
        
        <?php if (empty($success)): ?>
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-exclamation-triangle text-warning"></i>
                    Предупреждение
                </h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    После сброса пароля:
                </p>
                <ul class="list-unstyled small text-muted">
                    <li>• Старый пароль перестанет работать</li>
                    <li>• Пользователь должен использовать новый пароль</li>
                    <li>• Рекомендуется сменить пароль при первом входе</li>
                    <li>• Операция будет зафиксирована в логах</li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.detail-row {
    display: flex;
    margin-bottom: 0.75rem;
}

.detail-label {
    font-weight: 600;
    min-width: 80px;
    color: #6c757d;
}

.detail-value {
    flex: 1;
}
</style>

<script>
// Переключение между типами паролей
document.querySelectorAll('input[name="password_type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const customField = document.getElementById('customPasswordField');
        const customInput = document.getElementById('custom_password');
        
        if (this.value === 'custom') {
            customField.style.display = 'block';
            customInput.required = true;
        } else {
            customField.style.display = 'none';
            customInput.required = false;
            customInput.value = '';
        }
    });
});

// Показать/скрыть пароль
function togglePasswordVisibility() {
    const passwordField = document.getElementById('custom_password');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.className = 'bi bi-eye-slash';
    } else {
        passwordField.type = 'password';
        toggleIcon.className = 'bi bi-eye';
    }
}

// Копирование пароля
function copyPassword() {
    const passwordElement = document.getElementById('newPassword');
    const password = passwordElement.textContent;
    
    navigator.clipboard.writeText(password).then(function() {
        // Показываем уведомление об успешном копировании
        const button = event.target.closest('button');
        const originalText = button.innerHTML;
        
        button.innerHTML = '<i class="bi bi-check"></i> Скопировано';
        button.classList.remove('btn-outline-primary');
        button.classList.add('btn-success');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-primary');
        }, 2000);
    }).catch(function(err) {
        alert('Не удалось скопировать пароль: ' + err);
    });
}

// Подтверждение сброса пароля
document.querySelector('form').addEventListener('submit', function(e) {
    const employeeName = '<?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?>';
    const confirmMessage = `Вы уверены, что хотите сбросить пароль для сотрудника "${employeeName}"?\n\nЭто действие нельзя отменить.`;
    
    if (!confirm(confirmMessage)) {
        e.preventDefault();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>