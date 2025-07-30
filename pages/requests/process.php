<?php
/**
 * Обработка запросов (изменение статуса)
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('requests');

$user = getCurrentUser();

// Только администратор может обрабатывать запросы
if ($user['role'] !== ROLE_ADMIN) {
    header('Location: /pages/errors/403.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /pages/requests/index.php');
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrf_token)) {
    $_SESSION['error_message'] = 'Ошибка безопасности';
    header('Location: /pages/requests/index.php');
    exit;
}

$requestId = (int)($_POST['request_id'] ?? 0);
$action = trim($_POST['action'] ?? '');

if ($requestId <= 0 || !in_array($action, ['process', 'unprocess'])) {
    $_SESSION['error_message'] = 'Неверные параметры запроса';
    header('Location: /pages/requests/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем данные запроса
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        $_SESSION['error_message'] = 'Запрос не найден';
        header('Location: /pages/requests/index.php');
        exit;
    }
    
    if ($action === 'process') {
        // Обрабатываем запрос (переводим в статус "processed")
        if ($request['status'] === 'processed') {
            $_SESSION['error_message'] = 'Запрос уже обработан';
        } else {
            $stmt = $pdo->prepare("
                UPDATE requests 
                SET status = 'processed', processed_by = ?, processed_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$user['id'], $requestId]);
            
            logInfo('Request processed', [
                'request_id' => $requestId,
                'processed_by' => $user['id']
            ]);
            
            $_SESSION['success_message'] = 'Запрос успешно обработан';
        }
    } else {
        // Возвращаем запрос в обработку (переводим в статус "pending")
        if ($request['status'] === 'pending') {
            $_SESSION['error_message'] = 'Запрос уже находится в обработке';
        } else {
            $stmt = $pdo->prepare("
                UPDATE requests 
                SET status = 'pending', processed_by = NULL, processed_at = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$requestId]);
            
            logInfo('Request unprocessed', [
                'request_id' => $requestId,
                'unprocessed_by' => $user['id']
            ]);
            
            $_SESSION['success_message'] = 'Запрос возвращен в обработку';
        }
    }
    
} catch (Exception $e) {
    logError('Request processing error: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Произошла ошибка при обработке запроса';
}

// Определяем, куда перенаправить пользователя
$redirect = $_POST['redirect'] ?? 'list';
if ($redirect === 'view') {
    header('Location: /pages/requests/view.php?id=' . $requestId . '&success=1');
} else {
    header('Location: /pages/requests/index.php');
}
exit;
?>