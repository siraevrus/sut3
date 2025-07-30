<?php
/**
 * Архивирование компании
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$companyId = (int)($_GET['id'] ?? 0);

if (!$companyId) {
    $_SESSION['error_message'] = 'Компания не найдена';
    header('Location: /pages/companies/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о компании
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? AND status != -1");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if (!$company) {
        $_SESSION['error_message'] = 'Компания не найдена или уже архивирована';
        header('Location: /pages/companies/index.php');
        exit;
    }
    
    // Архивируем компанию
    $stmt = $pdo->prepare("UPDATE companies SET status = -1 WHERE id = ?");
    $stmt->execute([$companyId]);
    
    // Логируем архивирование
    logError('Company archived', [
        'company_id' => $companyId,
        'company_name' => $company['name'],
        'archived_by' => getCurrentUser()['id']
    ]);
    
    $_SESSION['success_message'] = 'Компания "' . $company['name'] . '" архивирована. Все связанные данные скрыты из основного списка.';
    
} catch (Exception $e) {
    logError('Archive company error: ' . $e->getMessage(), ['company_id' => $companyId]);
    $_SESSION['error_message'] = 'Произошла ошибка при архивировании компании';
}

header('Location: /pages/companies/index.php');
exit;
?>