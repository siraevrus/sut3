<?php
/**
 * Удаление склада (заглушка)
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$warehouseId = (int)($_GET['id'] ?? 0);

if (!$warehouseId) {
    $_SESSION['error_message'] = 'Склад не найден';
    header('Location: /pages/companies/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о складе
    $stmt = $pdo->prepare("
        SELECT w.*, c.id as company_id
        FROM warehouses w
        JOIN companies c ON w.company_id = c.id
        WHERE w.id = ?
    ");
    $stmt->execute([$warehouseId]);
    $warehouse = $stmt->fetch();
    
    if (!$warehouse) {
        $_SESSION['error_message'] = 'Склад не найден';
        header('Location: /pages/companies/index.php');
        exit;
    }
    
    // Проверяем, есть ли связанные данные
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE warehouse_id = ? AND status = 1");
    $stmt->execute([$warehouseId]);
    $employeesCount = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE warehouse_id = ? AND quantity > 0");
    $stmt->execute([$warehouseId]);
    $inventoryCount = $stmt->fetchColumn();
    
    if ($employeesCount > 0 || $inventoryCount > 0) {
        $_SESSION['error_message'] = 'Нельзя удалить склад: есть привязанные сотрудники (' . $employeesCount . ') или остатки товаров (' . $inventoryCount . ')';
    } else {
        // Помечаем склад как удаленный
        $stmt = $pdo->prepare("UPDATE warehouses SET status = -1 WHERE id = ?");
        $stmt->execute([$warehouseId]);
        
        // Логируем удаление
        logError('Warehouse deleted', [
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouse['name'],
            'company_id' => $warehouse['company_id'],
            'deleted_by' => getCurrentUser()['id']
        ]);
        
        $_SESSION['success_message'] = 'Склад "' . $warehouse['name'] . '" успешно удален';
    }
    
} catch (Exception $e) {
    logError('Delete warehouse error: ' . $e->getMessage(), ['warehouse_id' => $warehouseId]);
    $_SESSION['error_message'] = 'Произошла ошибка при удалении склада';
}

header('Location: /pages/companies/view.php?id=' . $warehouse['company_id']);
exit;
?>