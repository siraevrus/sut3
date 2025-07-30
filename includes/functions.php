<?php
/**
 * Общие функции системы
 * Система складского учета (SUT)
 */

/**
 * Проверка авторизации пользователя
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Получение данных текущего пользователя
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'login' => $_SESSION['user_login'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
        'company_id' => $_SESSION['company_id'] ?? null,
        'warehouse_id' => $_SESSION['warehouse_id'] ?? null
    ];
}

/**
 * Проверка роли пользователя
 */
function hasRole($role) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    if (is_array($role)) {
        return in_array($user['role'], $role);
    }
    
    return $user['role'] === $role;
}

/**
 * Проверка прав доступа к разделу
 */
function hasAccessToSection($section) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    $role = $user['role'];
    
    $permissions = [
        'dashboard' => [ROLE_ADMIN],
        'companies' => [ROLE_ADMIN],
        'employees' => [ROLE_ADMIN],
        'products' => [ROLE_ADMIN, ROLE_PC_OPERATOR],
        'requests' => [ROLE_ADMIN, ROLE_WAREHOUSE_WORKER, ROLE_SALES_MANAGER],
        'inventory' => [ROLE_ADMIN, ROLE_PC_OPERATOR, ROLE_WAREHOUSE_WORKER, ROLE_SALES_MANAGER],
        'goods_in_transit' => [ROLE_ADMIN, ROLE_PC_OPERATOR, ROLE_WAREHOUSE_WORKER, ROLE_SALES_MANAGER],
        'sales' => [ROLE_ADMIN, ROLE_WAREHOUSE_WORKER],
        'receiving' => [ROLE_ADMIN, ROLE_WAREHOUSE_WORKER],
        'product_templates' => [ROLE_ADMIN]
    ];
    
    return isset($permissions[$section]) && in_array($role, $permissions[$section]);
}

/**
 * Редирект с проверкой авторизации
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: /pages/auth/login.php');
        exit;
    }
}

/**
 * Редирект с проверкой прав доступа
 */
function requireAccess($section) {
    requireAuth();
    
    if (!hasAccessToSection($section)) {
        header('Location: /pages/errors/403.php');
        exit;
    }
}

/**
 * Безопасный вывод HTML
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Хеширование пароля
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
}

/**
 * Проверка пароля
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Генерация CSRF токена
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Проверка CSRF токена
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Форматирование даты
 */
function formatDate($date, $format = 'd.m.Y H:i') {
    if (!$date) return '';
    return date($format, strtotime($date));
}

/**
 * Форматирование числа
 */
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals, ',', ' ');
}

/**
 * Проверка загружаемого файла
 */
function validateUploadedFile($file) {
    $errors = [];
    
    // Проверка на ошибки загрузки
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Ошибка при загрузке файла';
        return $errors;
    }
    
    // Проверка размера файла
    if ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = 'Размер файла превышает допустимый лимит';
    }
    
    // Проверка типа файла
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_FILE_TYPES)) {
        $errors[] = 'Недопустимый тип файла';
    }
    
    return $errors;
}

/**
 * Сохранение загруженного файла
 */
function saveUploadedFile($file, $directory = '') {
    $errors = validateUploadedFile($file);
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = uniqid() . '.' . $extension;
    $uploadPath = UPLOAD_DIR . $directory . '/';
    
    if (!is_dir($uploadPath)) {
        mkdir($uploadPath, 0755, true);
    }
    
    $fullPath = $uploadPath . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $fullPath)) {
        return [
            'success' => true, 
            'filename' => $filename,
            'path' => $directory . '/' . $filename,
            'original_name' => $file['name']
        ];
    } else {
        return ['success' => false, 'errors' => ['Не удалось сохранить файл']];
    }
}

/**
 * JSON ответ для AJAX
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Логирование ошибок
 */
function logError($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($context)) {
        $logMessage .= ' - Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($logMessage . PHP_EOL, 3, __DIR__ . '/../logs/error.log');
}

/**
 * Создание директории для логов если не существует
 */
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}
?>