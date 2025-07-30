<?php
/**
 * Главная страница системы складского учета
 * Перенаправляет на соответствующую страницу в зависимости от авторизации
 */

require_once __DIR__ . '/config/config.php';

// Если пользователь не авторизован, перенаправляем на страницу входа
if (!isLoggedIn()) {
    header('Location: /pages/auth/login.php');
    exit;
}

// Если авторизован, перенаправляем на дашборд или доступную страницу
if (hasAccessToSection('dashboard')) {
    header('Location: /pages/dashboard.php');
} elseif (hasAccessToSection('inventory')) {
    header('Location: /pages/inventory/index.php');
} elseif (hasAccessToSection('products')) {
    header('Location: /pages/products/index.php');
} elseif (hasAccessToSection('requests')) {
    header('Location: /pages/requests/index.php');
} else {
    // Если нет доступа ни к одному разделу
    header('Location: /pages/errors/403.php');
}

exit;
?>