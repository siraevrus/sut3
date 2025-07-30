<?php
/**
 * Блокировка сотрудника
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
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 1");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        $_SESSION['error_message'] = 'Сотрудник не найден или уже заблокирован';
        header('Location: /pages/employees/index.php');
        exit;
    }
    
    // Проверяем, что не блокируем самого себя
    $currentUser = getCurrentUser();
    if ($currentUser['id'] == $employeeId) {
        $_SESSION['error_message'] = 'Нельзя заблокировать самого себя';
        header('Location: /pages/employees/view.php?id=' . $employeeId);
        exit;
    }
    
    // Блокируем сотрудника
    $stmt = $pdo->prepare("UPDATE users SET status = 0, blocked_at = NOW() WHERE id = ?");
    $stmt->execute([$employeeId]);
    
    // Завершаем все активные сессии пользователя
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$employeeId]);
    
    // Логируем блокировку
    logError('Employee blocked', [
        'employee_id' => $employeeId,
        'employee_login' => $employee['login'],
        'blocked_by' => $currentUser['id']
    ]);
    
    $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
    $_SESSION['success_message'] = 'Сотрудник "' . $fullName . '" заблокирован. Все активные сессии завершены.';
    
} catch (Exception $e) {
    logError('Block employee error: ' . $e->getMessage(), ['employee_id' => $employeeId]);
    $_SESSION['error_message'] = 'Произошла ошибка при блокировке сотрудника';
}

header('Location: /pages/employees/view.php?id=' . $employeeId);
exit;
?>