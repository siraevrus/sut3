<?php
/**
 * Завершение сессии сотрудника
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('employees');

$employeeId = (int)($_GET['id'] ?? 0);
$sessionId = $_GET['session'] ?? '';

if (!$employeeId || !$sessionId) {
    $_SESSION['error_message'] = 'Некорректные параметры';
    header('Location: /pages/employees/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Проверяем, что сессия принадлежит указанному пользователю
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_sessions WHERE id = ? AND user_id = ?");
    $stmt->execute([$sessionId, $employeeId]);
    
    if ($stmt->fetchColumn() == 0) {
        $_SESSION['error_message'] = 'Сессия не найдена';
        header('Location: /pages/employees/view.php?id=' . $employeeId);
        exit;
    }
    
    // Завершаем сессию
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE id = ? AND user_id = ?");
    $stmt->execute([$sessionId, $employeeId]);
    
    // Логируем действие
    logError('Session terminated by admin', [
        'employee_id' => $employeeId,
        'session_id' => $sessionId,
        'terminated_by' => getCurrentUser()['id']
    ]);
    
    $_SESSION['success_message'] = 'Сессия успешно завершена';
    
} catch (Exception $e) {
    logError('Terminate session error: ' . $e->getMessage(), [
        'employee_id' => $employeeId,
        'session_id' => $sessionId
    ]);
    $_SESSION['error_message'] = 'Произошла ошибка при завершении сессии';
}

header('Location: /pages/employees/view.php?id=' . $employeeId);
exit;
?>