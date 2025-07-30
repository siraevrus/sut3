<?php
/**
 * Users Endpoint
 * Система складского учета (SUT)
 */

$userId = $pathParts[1] ?? null;

switch ($method) {
    case 'GET':
        if ($userId) {
            getUser($userId);
        } else {
            getUsers();
        }
        break;
        
    case 'POST':
        createUser();
        break;
        
    case 'PUT':
        if (!$userId) {
            ApiResponse::error('User ID required', 400);
        }
        updateUser($userId);
        break;
        
    case 'DELETE':
        if (!$userId) {
            ApiResponse::error('User ID required', 400);
        }
        deleteUser($userId);
        break;
        
    default:
        ApiResponse::error('Method not allowed', 405);
}

/**
 * Получение списка пользователей
 */
function getUsers() {
    ApiAuth::requireRole('admin');
    
    $params = ApiResponse::getQueryParams([
        'page' => 1,
        'limit' => 20,
        'search' => '',
        'role' => '',
        'status' => '',
        'company_id' => 0
    ]);
    
    try {
        $pdo = getDBConnection();
        
        // Строим запрос с фильтрами
        $where = ['1=1'];
        $bindings = [];
        
        if (!empty($params['search'])) {
            $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.login LIKE ?)";
            $searchTerm = '%' . $params['search'] . '%';
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
        }
        
        if (!empty($params['role'])) {
            $where[] = "u.role = ?";
            $bindings[] = $params['role'];
        }
        
        if ($params['status'] !== '') {
            $where[] = "u.status = ?";
            $bindings[] = $params['status'];
        }
        
        if ($params['company_id'] > 0) {
            $where[] = "u.company_id = ?";
            $bindings[] = $params['company_id'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Подсчитываем общее количество
        $countQuery = "
            SELECT COUNT(*) 
            FROM users u 
            WHERE $whereClause
        ";
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute($bindings);
        $total = $stmt->fetchColumn();
        
        // Получаем данные с пагинацией
        $offset = ($params['page'] - 1) * $params['limit'];
        
        $dataQuery = "
            SELECT 
                u.id,
                u.login,
                u.first_name,
                u.last_name,
                u.middle_name,
                u.phone,
                u.role,
                u.company_id,
                u.warehouse_id,
                u.status,
                u.blocked_at,
                u.last_login,
                u.created_at,
                c.name as company_name,
                w.name as warehouse_name
            FROM users u
            LEFT JOIN companies c ON u.company_id = c.id
            LEFT JOIN warehouses w ON u.warehouse_id = w.id
            WHERE $whereClause
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $pdo->prepare($dataQuery);
        $stmt->execute([...$bindings, $params['limit'], $offset]);
        $users = $stmt->fetchAll();
        
        // Форматируем данные
        $formattedUsers = array_map(function($user) {
            return ApiResponse::sanitizeData([
                'id' => $user['id'],
                'login' => $user['login'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'middle_name' => $user['middle_name'],
                'full_name' => trim($user['last_name'] . ' ' . $user['first_name'] . ' ' . $user['middle_name']),
                'phone' => $user['phone'],
                'role' => $user['role'],
                'company_id' => $user['company_id'],
                'warehouse_id' => $user['warehouse_id'],
                'company_name' => $user['company_name'],
                'warehouse_name' => $user['warehouse_name'],
                'status' => $user['status'],
                'blocked_at' => ApiResponse::formatDate($user['blocked_at']),
                'last_login' => ApiResponse::formatDate($user['last_login']),
                'created_at' => ApiResponse::formatDate($user['created_at'])
            ]);
        }, $users);
        
        ApiResponse::paginated($formattedUsers, $total, $params['page'], $params['limit']);
        
    } catch (Exception $e) {
        logError('API Get users error: ' . $e->getMessage());
        ApiResponse::error('Failed to retrieve users', 500);
    }
}

/**
 * Получение информации о пользователе
 */
function getUser($userId) {
    ApiAuth::requireAuth();
    $currentUser = ApiAuth::getCurrentUser();
    
    // Пользователи могут видеть только себя, админы - всех
    if ($currentUser['role'] !== 'admin' && $currentUser['id'] != $userId) {
        ApiResponse::forbidden('Access denied');
    }
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.login,
                u.first_name,
                u.last_name,
                u.middle_name,
                u.phone,
                u.role,
                u.company_id,
                u.warehouse_id,
                u.status,
                u.blocked_at,
                u.last_login,
                u.created_at,
                c.name as company_name,
                w.name as warehouse_name
            FROM users u
            LEFT JOIN companies c ON u.company_id = c.id
            LEFT JOIN warehouses w ON u.warehouse_id = w.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            ApiResponse::notFound('User not found');
        }
        
        $userData = ApiResponse::sanitizeData([
            'id' => $user['id'],
            'login' => $user['login'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'middle_name' => $user['middle_name'],
            'full_name' => trim($user['last_name'] . ' ' . $user['first_name'] . ' ' . $user['middle_name']),
            'phone' => $user['phone'],
            'role' => $user['role'],
            'company_id' => $user['company_id'],
            'warehouse_id' => $user['warehouse_id'],
            'company_name' => $user['company_name'],
            'warehouse_name' => $user['warehouse_name'],
            'status' => $user['status'],
            'blocked_at' => ApiResponse::formatDate($user['blocked_at']),
            'last_login' => ApiResponse::formatDate($user['last_login']),
            'created_at' => ApiResponse::formatDate($user['created_at'])
        ]);
        
        ApiResponse::success($userData);
        
    } catch (Exception $e) {
        logError('API Get user error: ' . $e->getMessage(), ['user_id' => $userId]);
        ApiResponse::error('Failed to retrieve user', 500);
    }
}

/**
 * Создание пользователя
 */
function createUser() {
    ApiAuth::requireRole('admin');
    
    $input = ApiResponse::getJsonInput();
    ApiResponse::validateRequired($input, [
        'login', 'password', 'first_name', 'last_name', 'role'
    ]);
    
    // Валидация роли
    $allowedRoles = ['admin', 'pc_operator', 'warehouse_worker', 'sales_manager'];
    if (!in_array($input['role'], $allowedRoles)) {
        ApiResponse::validationError(['role' => 'Invalid role']);
    }
    
    // Валидация пароля
    if (strlen($input['password']) < 6) {
        ApiResponse::validationError(['password' => 'Password must be at least 6 characters']);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Проверяем уникальность логина
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login = ?");
        $stmt->execute([$input['login']]);
        if ($stmt->fetchColumn() > 0) {
            ApiResponse::validationError(['login' => 'Login already exists']);
        }
        
        // Создаем пользователя
        $stmt = $pdo->prepare("
            INSERT INTO users (
                login, password, first_name, last_name, middle_name, 
                phone, role, company_id, warehouse_id, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        $hashedPassword = hashPassword($input['password']);
        
        $stmt->execute([
            $input['login'],
            $hashedPassword,
            $input['first_name'],
            $input['last_name'],
            $input['middle_name'] ?? null,
            $input['phone'] ?? null,
            $input['role'],
            $input['company_id'] ?? null,
            $input['warehouse_id'] ?? null
        ]);
        
        $newUserId = $pdo->lastInsertId();
        
        // Логируем создание
        logError('API User created', [
            'new_user_id' => $newUserId,
            'login' => $input['login'],
            'created_by' => ApiAuth::getCurrentUser()['id']
        ]);
        
        ApiResponse::success(['id' => $newUserId], 'User created successfully', 201);
        
    } catch (Exception $e) {
        logError('API Create user error: ' . $e->getMessage());
        ApiResponse::error('Failed to create user', 500);
    }
}

/**
 * Обновление пользователя
 */
function updateUser($userId) {
    ApiAuth::requireAuth();
    $currentUser = ApiAuth::getCurrentUser();
    
    // Пользователи могут редактировать только себя, админы - всех
    if ($currentUser['role'] !== 'admin' && $currentUser['id'] != $userId) {
        ApiResponse::forbidden('Access denied');
    }
    
    $input = ApiResponse::getJsonInput();
    
    try {
        $pdo = getDBConnection();
        
        // Проверяем существование пользователя
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            ApiResponse::notFound('User not found');
        }
        
        $updates = [];
        $bindings = [];
        
        // Обновляемые поля
        $allowedFields = ['first_name', 'last_name', 'middle_name', 'phone'];
        
        // Админы могут обновлять дополнительные поля
        if ($currentUser['role'] === 'admin') {
            $allowedFields = array_merge($allowedFields, [
                'login', 'role', 'company_id', 'warehouse_id', 'status'
            ]);
        }
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updates[] = "$field = ?";
                $bindings[] = $input[$field];
            }
        }
        
        // Обработка смены пароля
        if (isset($input['password'])) {
            if (strlen($input['password']) < 6) {
                ApiResponse::validationError(['password' => 'Password must be at least 6 characters']);
            }
            
            $updates[] = "password = ?";
            $bindings[] = hashPassword($input['password']);
        }
        
        if (empty($updates)) {
            ApiResponse::error('No fields to update', 400);
        }
        
        $bindings[] = $userId;
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET " . implode(', ', $updates) . " 
            WHERE id = ?
        ");
        $stmt->execute($bindings);
        
        // Логируем обновление
        logError('API User updated', [
            'user_id' => $userId,
            'updated_by' => $currentUser['id'],
            'fields' => array_keys($input)
        ]);
        
        ApiResponse::success(null, 'User updated successfully');
        
    } catch (Exception $e) {
        logError('API Update user error: ' . $e->getMessage(), ['user_id' => $userId]);
        ApiResponse::error('Failed to update user', 500);
    }
}

/**
 * Удаление (блокировка) пользователя
 */
function deleteUser($userId) {
    ApiAuth::requireRole('admin');
    
    try {
        $pdo = getDBConnection();
        
        // Проверяем существование пользователя
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            ApiResponse::notFound('User not found');
        }
        
        // Нельзя удалить себя
        if ($userId == ApiAuth::getCurrentUser()['id']) {
            ApiResponse::error('Cannot delete yourself', 400);
        }
        
        // Блокируем пользователя
        $stmt = $pdo->prepare("
            UPDATE users 
            SET status = 0, blocked_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        // Отзываем все токены пользователя
        ApiAuth::revokeAllTokens($userId);
        
        // Логируем блокировку
        logError('API User blocked', [
            'user_id' => $userId,
            'login' => $user['login'],
            'blocked_by' => ApiAuth::getCurrentUser()['id']
        ]);
        
        ApiResponse::success(null, 'User blocked successfully');
        
    } catch (Exception $e) {
        logError('API Delete user error: ' . $e->getMessage(), ['user_id' => $userId]);
        ApiResponse::error('Failed to block user', 500);
    }
}
?>