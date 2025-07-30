<?php
/**
 * API Authentication System
 * Система складского учета (SUT)
 */

class ApiAuth {
    
    private static $currentUser = null;
    
    /**
     * Генерация API токена
     */
    public static function generateToken($userId) {
        $payload = [
            'user_id' => $userId,
            'issued_at' => time(),
            'expires_at' => time() + (30 * 24 * 60 * 60), // 30 дней
            'random' => bin2hex(random_bytes(16))
        ];
        
        $token = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $token, self::getSecretKey());
        
        return $token . '.' . $signature;
    }
    
    /**
     * Проверка API токена
     */
    public static function validateToken($token) {
        if (empty($token)) {
            return false;
        }
        
        // Убираем Bearer префикс если есть
        $token = str_replace('Bearer ', '', $token);
        
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        
        list($payload, $signature) = $parts;
        
        // Проверяем подпись
        $expectedSignature = hash_hmac('sha256', $payload, self::getSecretKey());
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }
        
        // Декодируем payload
        $data = json_decode(base64_decode($payload), true);
        if (!$data) {
            return false;
        }
        
        // Проверяем срок действия
        if ($data['expires_at'] < time()) {
            return false;
        }
        
        // Проверяем существование пользователя
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("
                SELECT u.*, c.name as company_name, w.name as warehouse_name
                FROM users u
                LEFT JOIN companies c ON u.company_id = c.id
                LEFT JOIN warehouses w ON u.warehouse_id = w.id
                WHERE u.id = ? AND u.status = 1
            ");
            $stmt->execute([$data['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return false;
            }
            
            self::$currentUser = $user;
            return true;
            
        } catch (Exception $e) {
            logError('API Token validation error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получение текущего пользователя API
     */
    public static function getCurrentUser() {
        return self::$currentUser;
    }
    
    /**
     * Проверка аутентификации
     */
    public static function requireAuth() {
        $token = self::getTokenFromRequest();
        
        if (!self::validateToken($token)) {
            ApiResponse::unauthorized('Invalid or expired token');
        }
    }
    
    /**
     * Проверка роли пользователя
     */
    public static function requireRole($roles) {
        self::requireAuth();
        
        $user = self::getCurrentUser();
        if (!$user) {
            ApiResponse::unauthorized();
        }
        
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        if (!in_array($user['role'], $roles)) {
            ApiResponse::forbidden('Insufficient permissions');
        }
    }
    
    /**
     * Получение токена из запроса
     */
    public static function getTokenFromRequest() {
        // Проверяем заголовок Authorization
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            return $headers['Authorization'];
        }
        
        // Проверяем параметр token
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }
        
        // Проверяем POST параметр
        if (isset($_POST['token'])) {
            return $_POST['token'];
        }
        
        return null;
    }
    
    /**
     * Получение секретного ключа
     */
    private static function getSecretKey() {
        // В продакшене должен быть в переменных окружения
        return hash('sha256', APP_NAME . DB_NAME . 'api_secret_key_2025');
    }
    
    /**
     * Сохранение токена в базе данных для отзыва
     */
    public static function storeToken($userId, $token, $deviceInfo = null) {
        try {
            $pdo = getDBConnection();
            
            // Декодируем токен для получения данных
            $parts = explode('.', $token);
            $payload = json_decode(base64_decode($parts[0]), true);
            
            $stmt = $pdo->prepare("
                INSERT INTO api_tokens (user_id, token_hash, expires_at, device_info, created_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    last_used = NOW(),
                    device_info = VALUES(device_info)
            ");
            
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', $payload['expires_at']);
            
            $stmt->execute([
                $userId,
                $tokenHash,
                $expiresAt,
                $deviceInfo ? json_encode($deviceInfo) : null
            ]);
            
        } catch (Exception $e) {
            logError('Store token error: ' . $e->getMessage());
        }
    }
    
    /**
     * Отзыв токена
     */
    public static function revokeToken($token) {
        try {
            $pdo = getDBConnection();
            $tokenHash = hash('sha256', $token);
            
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE token_hash = ?");
            $stmt->execute([$tokenHash]);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            logError('Revoke token error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Отзыв всех токенов пользователя
     */
    public static function revokeAllTokens($userId) {
        try {
            $pdo = getDBConnection();
            
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            logError('Revoke all tokens error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Очистка истекших токенов
     */
    public static function cleanExpiredTokens() {
        try {
            $pdo = getDBConnection();
            
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE expires_at < NOW()");
            $stmt->execute();
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            logError('Clean expired tokens error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Получение активных токенов пользователя
     */
    public static function getUserTokens($userId) {
        try {
            $pdo = getDBConnection();
            
            $stmt = $pdo->prepare("
                SELECT 
                    token_hash,
                    device_info,
                    created_at,
                    last_used,
                    expires_at
                FROM api_tokens 
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY last_used DESC
            ");
            $stmt->execute([$userId]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            logError('Get user tokens error: ' . $e->getMessage());
            return [];
        }
    }
}

// Добавляем таблицу для токенов если не существует
try {
    $pdo = getDBConnection();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            device_info JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_api_tokens_user (user_id),
            INDEX idx_api_tokens_expires (expires_at)
        ) ENGINE=InnoDB COMMENT='API токены для мобильного приложения'
    ");
} catch (Exception $e) {
    logError('API tokens table creation error: ' . $e->getMessage());
}
?>