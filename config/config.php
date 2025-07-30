<?php
/**
 * Основная конфигурация системы
 * Система складского учета (SUT)
 */

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Настройки приложения
define('APP_NAME', 'Система складского учета');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost');

// Настройки сессии (1 месяц = 30 дней)
define('SESSION_LIFETIME', 30 * 24 * 60 * 60); // 30 дней в секундах
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
ini_set('session.cookie_lifetime', SESSION_LIFETIME);

// Настройки безопасности
define('HASH_ALGO', 'sha256');
define('PASSWORD_COST', 12);

// Настройки файлов
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_FILE_TYPES', [
    'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 
    'xls', 'xlsx', 'txt', 'odt', 'rtf'
]);

// Настройки пагинации
define('ITEMS_PER_PAGE', 20);

// Роли пользователей
define('ROLE_ADMIN', 'admin');
define('ROLE_PC_OPERATOR', 'pc_operator');
define('ROLE_WAREHOUSE_WORKER', 'warehouse_worker');
define('ROLE_SALES_MANAGER', 'sales_manager');

// Статусы
define('STATUS_ACTIVE', 1);
define('STATUS_BLOCKED', 0);
define('STATUS_ARCHIVED', -1);

// Цветовая схема (из ТЗ)
define('COLOR_PRIMARY', '#2c5ccf');
define('COLOR_SECONDARY', '#38a169');
define('COLOR_DANGER', '#e53e3e');
define('COLOR_BACKGROUND', '#f7fafc');
define('COLOR_TEXT', '#2d3748');

// Подключение базы данных
require_once __DIR__ . '/database.php';

// Функции для работы с сессиями и безопасностью
require_once __DIR__ . '/../includes/functions.php';

// Установка часового пояса
date_default_timezone_set('Europe/Moscow');

// Настройки отображения ошибок (для разработки)
if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
?>