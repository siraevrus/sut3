<?php
/**
 * Менеджер сессий
 * Система складского учета (SUT)
 */

class SessionManager {
    
    /**
     * Инициализация системы сессий
     */
    public static function init() {
        // Настройки сессии устанавливаются только если сессия еще не запущена
        if (session_status() === PHP_SESSION_NONE) {
            // Настройки безопасности сессий
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_samesite', 'Lax');
            
            // Устанавливаем время жизни сессии
            ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
            ini_set('session.cookie_lifetime', SESSION_LIFETIME);
            
            // Запускаем сессию
            session_start();
        }
        
        // Проверяем валидность сессии
        self::validateSession();
        
        // Очищаем устаревшие сессии
        self::cleanExpiredSessions();
    }
    
    /**
     * Проверка валидности текущей сессии
     */
    private static function validateSession() {
        if (!isLoggedIn()) {
            return;
        }
        
        try {
            $pdo = getDBConnection();
            $sessionId = session_id();
            
            // Проверяем сессию в базе данных
            $stmt = $pdo->prepare("
                SELECT us.*, u.status as user_status 
                FROM user_sessions us 
                JOIN users u ON us.user_id = u.id 
                WHERE us.id = ? AND us.expires_at > NOW()
            ");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();
            
            if (!$session) {
                // Сессия не найдена или истекла
                self::destroySession();
                return;
            }
            
            if ($session['user_status'] != 1) {
                // Пользователь заблокирован
                self::destroySession();
                return;
            }
            
            // Обновляем время последней активности
            $stmt = $pdo->prepare("
                UPDATE user_sessions 
                SET last_activity = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$sessionId]);
            
            // Проверяем безопасность (IP адрес и User Agent)
            $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
            $currentUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            if ($session['ip_address'] !== $currentIP || 
                $session['user_agent'] !== $currentUA) {
                
                // Подозрительная активность - логируем
                logError('Suspicious session activity', [
                    'session_id' => $sessionId,
                    'user_id' => $session['user_id'],
                    'stored_ip' => $session['ip_address'],
                    'current_ip' => $currentIP,
                    'stored_ua' => substr($session['user_agent'], 0, 100),
                    'current_ua' => substr($currentUA, 0, 100)
                ]);
                
                // В продакшене можно завершить сессию
                // self::destroySession();
                // return;
            }
            
        } catch (Exception $e) {
            logError('Session validation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Уничтожение сессии
     */
    public static function destroySession() {
        try {
            $pdo = getDBConnection();
            $sessionId = session_id();
            
            // Удаляем сессию из базы данных
            if ($sessionId) {
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE id = ?");
                $stmt->execute([$sessionId]);
            }
            
        } catch (Exception $e) {
            logError('Session destroy error: ' . $e->getMessage());
        }
        
        // Очищаем сессию
        $_SESSION = [];
        
        // Удаляем cookie сессии
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Уничтожаем сессию
        session_destroy();
    }
    
    /**
     * Очистка устаревших сессий
     */
    private static function cleanExpiredSessions() {
        // Очищаем не чаще чем раз в час
        if (rand(1, 100) > 5) {
            return;
        }
        
        try {
            $pdo = getDBConnection();
            
            // Удаляем истекшие сессии
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()");
            $stmt->execute();
            
            $deletedCount = $stmt->rowCount();
            if ($deletedCount > 0) {
                logError("Cleaned expired sessions", ['count' => $deletedCount]);
            }
            
        } catch (Exception $e) {
            logError('Session cleanup error: ' . $e->getMessage());
        }
    }
    
    /**
     * Получение активных сессий пользователя
     */
    public static function getUserSessions($userId) {
        try {
            $pdo = getDBConnection();
            
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    ip_address,
                    user_agent,
                    last_activity,
                    expires_at,
                    created_at,
                    (id = ?) as is_current
                FROM user_sessions 
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY last_activity DESC
            ");
            $stmt->execute([session_id(), $userId]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            logError('Get user sessions error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Завершение всех сессий пользователя кроме текущей
     */
    public static function terminateOtherSessions($userId) {
        try {
            $pdo = getDBConnection();
            $currentSessionId = session_id();
            
            $stmt = $pdo->prepare("
                DELETE FROM user_sessions 
                WHERE user_id = ? AND id != ?
            ");
            $stmt->execute([$userId, $currentSessionId]);
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            logError('Terminate other sessions error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Завершение конкретной сессии
     */
    public static function terminateSession($sessionId, $userId) {
        try {
            $pdo = getDBConnection();
            
            $stmt = $pdo->prepare("
                DELETE FROM user_sessions 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$sessionId, $userId]);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            logError('Terminate session error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Продление сессии
     */
    public static function extendSession() {
        if (!isLoggedIn()) {
            return false;
        }
        
        try {
            $pdo = getDBConnection();
            $sessionId = session_id();
            $newExpiryTime = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
            
            $stmt = $pdo->prepare("
                UPDATE user_sessions 
                SET expires_at = ?, last_activity = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$newExpiryTime, $sessionId]);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            logError('Extend session error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получение статистики сессий
     */
    public static function getSessionStats() {
        try {
            $pdo = getDBConnection();
            
            // Активные сессии
            $stmt = $pdo->query("
                SELECT COUNT(*) as active_sessions 
                FROM user_sessions 
                WHERE expires_at > NOW()
            ");
            $activeCount = $stmt->fetchColumn();
            
            // Сессии за последние 24 часа
            $stmt = $pdo->query("
                SELECT COUNT(*) as daily_sessions 
                FROM user_sessions 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $dailyCount = $stmt->fetchColumn();
            
            // Уникальные пользователи онлайн
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT user_id) as online_users 
                FROM user_sessions 
                WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ");
            $onlineUsers = $stmt->fetchColumn();
            
            return [
                'active_sessions' => $activeCount,
                'daily_sessions' => $dailyCount,
                'online_users' => $onlineUsers
            ];
            
        } catch (Exception $e) {
            logError('Get session stats error: ' . $e->getMessage());
            return [
                'active_sessions' => 0,
                'daily_sessions' => 0,
                'online_users' => 0
            ];
        }
    }
}

// Инициализируем менеджер сессий при подключении файла
SessionManager::init();
?>