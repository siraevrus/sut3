<?php
/**
 * Страница ошибки 403 - Доступ запрещен
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Доступ запрещен';
http_response_code(403);
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
            background: <?= COLOR_BACKGROUND ?>;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .error-container {
            text-align: center;
            max-width: 600px;
            padding: 2rem;
        }
        
        .error-icon {
            font-size: 5rem;
            color: <?= COLOR_DANGER ?>;
            margin-bottom: 1rem;
        }
        
        .error-code {
            font-size: 3rem;
            font-weight: bold;
            color: <?= COLOR_DANGER ?>;
            margin-bottom: 1rem;
        }
        
        .error-message {
            font-size: 1.2rem;
            color: <?= COLOR_TEXT ?>;
            margin-bottom: 2rem;
        }
        
        .error-description {
            color: #6c757d;
            margin-bottom: 2rem;
        }
        
        .btn-back {
            background: <?= COLOR_PRIMARY ?>;
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 500;
        }
        
        .btn-back:hover {
            background: #1e4bb8;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="bi bi-shield-exclamation"></i>
        </div>
        
        <div class="error-code">403</div>
        
        <div class="error-message">
            Доступ запрещен
        </div>
        
        <div class="error-description">
            У вас недостаточно прав для доступа к этому разделу системы.<br>
            Обратитесь к администратору для получения необходимых разрешений.
        </div>
        
        <div class="d-flex gap-3 justify-content-center">
            <button onclick="history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
                Назад
            </button>
            
            <?php if (isLoggedIn()): ?>
            <a href="/" class="btn btn-primary btn-back">
                <i class="bi bi-house"></i>
                На главную
            </a>
            <?php else: ?>
            <a href="/pages/auth/login.php" class="btn btn-primary btn-back">
                <i class="bi bi-box-arrow-in-right"></i>
                Войти в систему
            </a>
            <?php endif; ?>
        </div>
        
        <?php if (isLoggedIn()): ?>
        <div class="mt-4 text-muted">
            <small>
                Пользователь: <?= e(getCurrentUser()['name']) ?> 
                (<?= e(getCurrentUser()['role']) ?>)
            </small>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>