<?php
/**
 * Authentication Endpoint
 * Система складского учета (SUT)
 */

$action = $pathParts[1] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
        
    case 'logout':
        handleLogout();
        break;
        
    case 'me':
        handleMe();
        break;
        
    case 'refresh':
        handleRefresh();
        break;
        
    default:
        ApiResponse::error('Invalid auth action', 404);
}

/**
 * Аутентификация пользователя
 */
function handleLogin() {
    global $method;
    
    if ($method !== 'POST') {
        ApiResponse::error('Method not allowed', 405);
    }
    
    $input = ApiResponse::getJsonInput();
    ApiResponse::validateRequired($input, ['login', 'password']);
    
    $login = trim($input['login']);
    $password = $input['password'];
    $deviceInfo = $input['device_info'] ?? null;
    
    try {
        $pdo = getDBConnection();
        
        // Ищем пользователя
        $stmt = $pdo->prepare("
            SELECT u.*, c.name as company_name, w.name as warehouse_name
            FROM users u
            LEFT JOIN companies c ON u.company_id = c.id
            LEFT JOIN warehouses w ON u.warehouse_id = w.id
            WHERE u.login = ? AND u.status = 1
        ");
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        
        if (!$user || !verifyPassword($password, $user['password'])) {
            ApiResponse::error('Invalid credentials', 401);
        }
        
        if ($user['status'] == 0) {
            ApiResponse::error('Account is blocked', 403);
        }
        
        // Генерируем токен
        $token = ApiAuth::generateToken($user['id']);
        
        // Сохраняем токен
        ApiAuth::storeToken($user['id'], $token, $deviceInfo);
        
        // Обновляем время последнего входа
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Логируем вход
        logError('API Login successful', [
            'user_id' => $user['id'],
            'login' => $login,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // Подготавливаем данные пользователя
        $userData = ApiResponse::sanitizeData([
            'id' => $user['id'],
            'login' => $user['login'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'middle_name' => $user['middle_name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'company_id' => $user['company_id'],
            'warehouse_id' => $user['warehouse_id'],
            'company_name' => $user['company_name'],
            'warehouse_name' => $user['warehouse_name'],
            'last_login' => ApiResponse::formatDate($user['last_login'])
        ]);
        
        ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 30 * 24 * 60 * 60, // 30 дней в секундах
            'user' => $userData
        ], 'Login successful');
        
    } catch (Exception $e) {
        logError('API Login error: ' . $e->getMessage(), ['login' => $login]);
        ApiResponse::error('Authentication failed', 500);
    }
}

/**
 * Выход из системы
 */
function handleLogout() {
    global $method;
    
    if ($method !== 'POST') {
        ApiResponse::error('Method not allowed', 405);
    }
    
    $token = ApiAuth::getTokenFromRequest();
    
    if ($token) {
        ApiAuth::revokeToken($token);
    }
    
    ApiResponse::success(null, 'Logout successful');
}

/**
 * Информация о текущем пользователе
 */
function handleMe() {
    global $method;
    
    if ($method !== 'GET') {
        ApiResponse::error('Method not allowed', 405);
    }
    
    ApiAuth::requireAuth();
    $user = ApiAuth::getCurrentUser();
    
    $userData = ApiResponse::sanitizeData([
        'id' => $user['id'],
        'login' => $user['login'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'middle_name' => $user['middle_name'],
        'phone' => $user['phone'],
        'role' => $user['role'],
        'company_id' => $user['company_id'],
        'warehouse_id' => $user['warehouse_id'],
        'company_name' => $user['company_name'],
        'warehouse_name' => $user['warehouse_name'],
        'last_login' => ApiResponse::formatDate($user['last_login'])
    ]);
    
    ApiResponse::success($userData, 'User information');
}

/**
 * Обновление токена
 */
function handleRefresh() {
    global $method;
    
    if ($method !== 'POST') {
        ApiResponse::error('Method not allowed', 405);
    }
    
    ApiAuth::requireAuth();
    $user = ApiAuth::getCurrentUser();
    
    // Генерируем новый токен
    $newToken = ApiAuth::generateToken($user['id']);
    
    // Отзываем старый токен
    $oldToken = ApiAuth::getTokenFromRequest();
    if ($oldToken) {
        ApiAuth::revokeToken($oldToken);
    }
    
    // Сохраняем новый токен
    $input = ApiResponse::getJsonInput();
    $deviceInfo = $input['device_info'] ?? null;
    ApiAuth::storeToken($user['id'], $newToken, $deviceInfo);
    
    ApiResponse::success([
        'token' => $newToken,
        'token_type' => 'Bearer',
        'expires_in' => 30 * 24 * 60 * 60
    ], 'Token refreshed');
}
?>