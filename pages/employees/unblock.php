<?php
/**
 * Разблокировка сотрудника
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
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 0");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        $_SESSION['error_message'] = 'Сотрудник не найден или не заблокирован';
        header('Location: /pages/employees/index.php');
        exit;
    }
    
    // Разблокируем сотрудника
    $stmt = $pdo->prepare("UPDATE users SET status = 1, blocked_at = NULL WHERE id = ?");
    $stmt->execute([$employeeId]);
    
    // Логируем разблокировку
    logError('Employee unblocked', [
        'employee_id' => $employeeId,
        'employee_login' => $employee['login'],
        'unblocked_by' => getCurrentUser()['id']
    ]);
    
    $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
    $_SESSION['success_message'] = 'Сотрудник "' . $fullName . '" разблокирован';
    
} catch (Exception $e) {
    logError('Unblock employee error: ' . $e->getMessage(), ['employee_id' => $employeeId]);
    $_SESSION['error_message'] = 'Произошла ошибка при разблокировке сотрудника';
}

header('Location: /pages/employees/view.php?id=' . $employeeId);
exit;
?>