<?php
/**
 * Удаление товара
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('products');

$productId = (int)($_GET['id'] ?? 0);

if (!$productId) {
    $_SESSION['error_message'] = 'Товар не найден';
    header('Location: /pages/products/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    $user = getCurrentUser();
    
    // Проверяем существование товара и права доступа
    $query = "
        SELECT 
            p.*,
            pt.name as template_name,
            w.name as warehouse_name,
            c.name as company_name
        FROM products p
        JOIN product_templates pt ON p.template_id = pt.id
        JOIN warehouses w ON p.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        WHERE p.id = ?
    ";
    
    // Ограничения по роли пользователя
    if ($user['role'] !== ROLE_ADMIN) {
        $query .= " AND w.company_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$productId, $user['company_id']]);
    } else {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$productId]);
    }
    
    $product = $stmt->fetch();
    
    if (!$product) {
        $_SESSION['error_message'] = 'Товар не найден или недоступен';
        header('Location: /pages/products/index.php');
        exit;
    }
    
    // Проверяем, нет ли связанных записей (продажи, остатки и т.д.)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE template_id = ?");
    $stmt->execute([$product['template_id']]);
    $salesCount = $stmt->fetchColumn();
    
    if ($salesCount > 0) {
        $_SESSION['error_message'] = 'Нельзя удалить товар, по которому есть записи о продажах (' . $salesCount . ' шт.)';
        header('Location: /pages/products/view.php?id=' . $productId);
        exit;
    }
    
    // Удаляем товар
    $pdo->beginTransaction();
    
    try {
        // Получаем атрибуты товара, чтобы узнать количество
        $attributes = json_decode($product['attributes'], true) ?: [];
        $quantityValue = isset($attributes['quantity']) ? (float)$attributes['quantity'] : 1;
        
        // Уменьшаем количество в inventory
        $stmt = $pdo->prepare("
            UPDATE inventory 
            SET quantity = quantity - ? 
            WHERE warehouse_id = ? AND template_id = ?
        ");
        $stmt->execute([$quantityValue, $product['warehouse_id'], $product['template_id']]);
        
        // Удаляем сам товар
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = 'Товар "' . $product['template_name'] . '" успешно удален';
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    logError('Product deletion error: ' . $e->getMessage(), ['product_id' => $productId]);
    $_SESSION['error_message'] = 'Произошла ошибка при удалении товара';
}

header('Location: /pages/products/index.php');
exit;
?>