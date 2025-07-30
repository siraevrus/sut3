<?php
/**
 * Страница авторизации
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

// Если пользователь уже авторизован, перенаправляем на главную
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';
$login = '';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Проверка CSRF токена
    if (!verifyCSRFToken($csrf_token)) {
        $error = 'Ошибка безопасности. Попробуйте еще раз.';
    } elseif (empty($login) || empty($password)) {
        $error = 'Заполните все поля';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Ищем пользователя по логину
            $stmt = $pdo->prepare("
                SELECT u.*, c.name as company_name, w.name as warehouse_name 
                FROM users u 
                LEFT JOIN companies c ON u.company_id = c.id 
                LEFT JOIN warehouses w ON u.warehouse_id = w.id 
                WHERE u.login = ? AND u.status = 1
            ");
            $stmt->execute([$login]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = 'Неверный логин или пароль, попробуйте восстановить пароль или обратитесь к администратору';
            } elseif ($user['status'] == 0) {
                $error = 'Ваш аккаунт заблокирован';
            } elseif (!verifyPassword($password, $user['password'])) {
                $error = 'Неверный логин или пароль, попробуйте восстановить пароль или обратитесь к администратору';
            } else {
                // Успешная авторизация
                
                // Создаем сессию
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_login'] = $user['login'];
                $_SESSION['user_name'] = trim($user['last_name'] . ' ' . $user['first_name'] . ' ' . $user['middle_name']);
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['company_id'] = $user['company_id'];
                $_SESSION['warehouse_id'] = $user['warehouse_id'];
                $_SESSION['company_name'] = $user['company_name'];
                $_SESSION['warehouse_name'] = $user['warehouse_name'];
                
                // Обновляем время последнего входа
                $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                // Сохраняем сессию в базе данных
                $sessionId = session_id();
                $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
                
                $stmt = $pdo->prepare("
                    INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        last_activity = NOW(), 
                        expires_at = ?, 
                        ip_address = ?, 
                        user_agent = ?
                ");
                $stmt->execute([
                    $sessionId, 
                    $user['id'], 
                    $_SERVER['REMOTE_ADDR'] ?? '', 
                    $_SERVER['HTTP_USER_AGENT'] ?? '', 
                    $expiresAt,
                    $expiresAt,
                    $_SERVER['REMOTE_ADDR'] ?? '', 
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                // Перенаправляем на главную страницу
                header('Location: /');
                exit;
            }
            
        } catch (Exception $e) {
            logError('Login error: ' . $e->getMessage(), ['login' => $login]);
            $error = 'Произошла ошибка при входе в систему';
        }
    }
}

$pageTitle = 'Вход в систему';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= APP_NAME ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/assets/css/style.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, <?= COLOR_PRIMARY ?>, #1e4bb8);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
        }
        
        .login-header {
            background: <?= COLOR_PRIMARY ?>;
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 1.5rem;
            margin: 0;
            font-weight: 600;
        }
        
        .login-header .subtitle {
            opacity: 0.9;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .form-floating {
            margin-bottom: 1rem;
        }
        
        .btn-login {
            background: <?= COLOR_PRIMARY ?>;
            border: none;
            padding: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            background: #1e4bb8;
            transform: translateY(-1px);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .system-info {
            background: <?= COLOR_BACKGROUND ?>;
            padding: 1.5rem;
            text-align: center;
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        @media (max-width: 576px) {
            .login-card {
                margin: 1rem;
            }
            
            .login-header, .login-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <i class="bi bi-boxes" style="font-size: 2rem; margin-bottom: 1rem;"></i>
            <h1><?= APP_NAME ?></h1>
            <div class="subtitle">Авторизация в системе</div>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <?= e($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="form-floating">
                    <input 
                        type="text" 
                        class="form-control" 
                        id="login" 
                        name="login" 
                        placeholder="Логин"
                        value="<?= e($login) ?>"
                        required 
                        autocomplete="username"
                        autofocus
                    >
                    <label for="login">
                        <i class="bi bi-person"></i> Логин
                    </label>
                </div>
                
                <div class="form-floating">
                    <input 
                        type="password" 
                        class="form-control" 
                        id="password" 
                        name="password" 
                        placeholder="Пароль"
                        required 
                        autocomplete="current-password"
                    >
                    <label for="password">
                        <i class="bi bi-lock"></i> Пароль
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Войти в систему
                </button>
            </form>
        </div>
        
        <div class="system-info">
            <div><strong>Демонстрационные данные для входа:</strong></div>
            <div class="mt-2">
                <strong>Администратор:</strong> admin / admin123<br>
                <strong>Оператор:</strong> operator1 / password123<br>
                <strong>Склад:</strong> warehouse1 / password123<br>
                <strong>Менеджер:</strong> manager1 / password123
            </div>
            <div class="mt-3">
                Версия <?= APP_VERSION ?> | © <?= date('Y') ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Автофокус на поле логина при загрузке
        document.addEventListener('DOMContentLoaded', function() {
            const loginField = document.getElementById('login');
            if (loginField && !loginField.value) {
                loginField.focus();
            } else {
                const passwordField = document.getElementById('password');
                if (passwordField) {
                    passwordField.focus();
                }
            }
        });
        
        // Обработка формы с показом загрузчика
        document.querySelector('form').addEventListener('submit', function() {
            const button = this.querySelector('button[type="submit"]');
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Вход...';
        });
    </script>
</body>
</html>