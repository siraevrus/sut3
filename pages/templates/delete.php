<?php
/**
 * Удаление шаблона товара
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('product_templates');

$templateId = (int)($_GET['id'] ?? 0);

if (!$templateId) {
    $_SESSION['error_message'] = 'Шаблон не найден';
    header('Location: /pages/templates/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о шаблоне
    $stmt = $pdo->prepare("SELECT * FROM product_templates WHERE id = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    
    if (!$template) {
        $_SESSION['error_message'] = 'Шаблон не найден';
        header('Location: /pages/templates/index.php');
        exit;
    }
    
    // Проверяем, есть ли товары по этому шаблону
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE template_id = ?");
    $stmt->execute([$templateId]);
    $productsCount = $stmt->fetchColumn();
    
    if ($productsCount > 0) {
        $_SESSION['error_message'] = 'Нельзя удалить шаблон, по которому созданы товары (' . $productsCount . ' шт.)';
        header('Location: /pages/templates/view.php?id=' . $templateId);
        exit;
    }
    
    // Удаляем шаблон (характеристики удалятся автоматически благодаря CASCADE)
    $stmt = $pdo->prepare("DELETE FROM product_templates WHERE id = ?");
    $stmt->execute([$templateId]);
    
    // Логируем удаление
    logError('Template deleted', [
        'template_id' => $templateId,
        'template_name' => $template['name'],
        'deleted_by' => getCurrentUser()['id']
    ]);
    
    $_SESSION['success_message'] = 'Шаблон "' . $template['name'] . '" успешно удален';
    
} catch (Exception $e) {
    logError('Delete template error: ' . $e->getMessage(), ['template_id' => $templateId]);
    $_SESSION['error_message'] = 'Произошла ошибка при удалении шаблона';
}

header('Location: /pages/templates/index.php');
exit;
?>