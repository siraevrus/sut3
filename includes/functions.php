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
 * Расчет объема по формуле шаблона
 */
function calculateVolumeByFormula($formula, $attributes) {
    if (empty($formula) || empty($attributes)) {
        return null;
    }
    
    // Безопасная замена переменных в формуле
    $safeFormula = $formula;
    
    foreach ($attributes as $variable => $value) {
        // Проверяем, что значение является числом
        if (!is_numeric($value)) {
            continue;
        }
        
        // Заменяем переменную на значение
        $safeFormula = preg_replace('/\b' . preg_quote($variable, '/') . '\b/', $value, $safeFormula);
    }
    
    // Проверяем, что в формуле остались только числа и математические операторы
    if (!preg_match('/^[0-9+\-*\/\(\)\.\s]+$/', $safeFormula)) {
        throw new Exception('Формула содержит недопустимые символы');
    }
    
    // Вычисляем результат
    $result = null;
    try {
        // Используем eval с осторожностью - только для математических выражений
        $result = eval("return $safeFormula;");
        
        if ($result === false || !is_numeric($result)) {
            throw new Exception('Ошибка вычисления формулы');
        }
        
        // Округляем до 3 знаков после запятой
        $result = round($result, 3);
        
    } catch (ParseError $e) {
        throw new Exception('Синтаксическая ошибка в формуле: ' . $e->getMessage());
    } catch (Error $e) {
        throw new Exception('Ошибка выполнения формулы: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Валидация формулы шаблона
 */
function validateFormula($formula, $variables) {
    if (empty($formula)) {
        return ['valid' => true, 'message' => ''];
    }
    
    // Проверяем синтаксис формулы
    if (!preg_match('/^[a-zA-Z0-9+\-*\/\(\)\.\s_]+$/', $formula)) {
        return ['valid' => false, 'message' => 'Формула содержит недопустимые символы'];
    }
    
    // Проверяем, что все переменные в формуле существуют в атрибутах
    preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $formula, $matches);
    $formulaVariables = array_unique($matches[1]);
    
    // Исключаем числа из переменных
    $formulaVariables = array_filter($formulaVariables, function($var) {
        return !is_numeric($var);
    });
    
    $missingVariables = array_diff($formulaVariables, $variables);
    if (!empty($missingVariables)) {
        return [
            'valid' => false, 
            'message' => 'Переменные не найдены в атрибутах: ' . implode(', ', $missingVariables)
        ];
    }
    
    // Тестируем формулу с тестовыми данными
    $testAttributes = [];
    foreach ($variables as $var) {
        $testAttributes[$var] = 1; // Используем 1 для всех переменных
    }
    
    try {
        $result = calculateVolumeByFormula($formula, $testAttributes);
        if ($result === null) {
            return ['valid' => false, 'message' => 'Не удалось вычислить формулу с тестовыми данными'];
        }
    } catch (Exception $e) {
        return ['valid' => false, 'message' => 'Ошибка тестирования формулы: ' . $e->getMessage()];
    }
    
    return ['valid' => true, 'message' => 'Формула корректна'];
}

/**
 * Создание директории для логов если не существует
 */
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}
?>