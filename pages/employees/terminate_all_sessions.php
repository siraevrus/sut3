<?php
/**
 * Завершение всех сессий сотрудника
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('employees');

$employeeId = (int)($_GET['id'] ?? 0);

if (!$employeeId) {
    $_SESSION['error_message'] = 'Сотрудник не найден';
    header('Location: /pages/employees/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о сотруднике
    $stmt = $pdo->prepare("SELECT login, first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        $_SESSION['error_message'] = 'Сотрудник не найден';
        header('Location: /pages/employees/index.php');
        exit;
    }
    
    // Подсчитываем количество активных сессий
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$employeeId]);
    $sessionCount = $stmt->fetchColumn();
    
    // Завершаем все сессии пользователя
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$employeeId]);
    
    // Логируем действие
    logError('All sessions terminated by admin', [
        'employee_id' => $employeeId,
        'employee_login' => $employee['login'],
        'sessions_count' => $sessionCount,
        'terminated_by' => getCurrentUser()['id']
    ]);
    
    $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
    $_SESSION['success_message'] = "Все сессии пользователя \"{$fullName}\" завершены ({$sessionCount} сессий)";
    
} catch (Exception $e) {
    logError('Terminate all sessions error: ' . $e->getMessage(), ['employee_id' => $employeeId]);
    $_SESSION['error_message'] = 'Произошла ошибка при завершении сессий';
}

header('Location: /pages/employees/view.php?id=' . $employeeId);
exit;
?>