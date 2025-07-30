<?php
/**
 * Выход из системы
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

// Если пользователь не авторизован, перенаправляем на страницу входа
if (!isLoggedIn()) {
    header('Location: /pages/auth/login.php');
    exit;
}

try {
    $pdo = getDBConnection();
    $sessionId = session_id();
    
    // Удаляем сессию из базы данных
    if ($sessionId) {
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
    }
    
    // Логируем выход пользователя
    $user = getCurrentUser();
    if ($user) {
        logError('User logout', [
            'user_id' => $user['id'],
            'user_login' => $user['login'],
            'session_id' => $sessionId
        ]);
    }
    
} catch (Exception $e) {
    logError('Logout error: ' . $e->getMessage());
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

// Перенаправляем на страницу входа
header('Location: /pages/auth/login.php?logout=1');
exit;
?>