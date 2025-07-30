<?php
/**
 * Прямой тест получения атрибутов шаблона
 */

require_once 'config/config.php';

try {
    $pdo = getDBConnection();
    
    // Получаем атрибуты для шаблона ID=1
    $template_id = 1;
    
    $stmt = $pdo->prepare("
        SELECT * FROM template_attributes 
        WHERE template_id = ? 
        ORDER BY sort_order, id
    ");
    $stmt->execute([$template_id]);
    $attributes = $stmt->fetchAll();
    
    echo "Атрибуты для шаблона ID=$template_id:\n";
    echo "Найдено атрибутов: " . count($attributes) . "\n\n";
    
    foreach ($attributes as $attr) {
        echo "- {$attr['name']} ({$attr['variable']}) - тип: {$attr['data_type']}";
        if ($attr['is_required']) echo " [обязательный]";
        if ($attr['unit']) echo " [{$attr['unit']}]";
        if ($attr['options']) echo " варианты: {$attr['options']}";
        echo "\n";
    }
    
    // Тестируем JSON ответ
    echo "\nJSON ответ:\n";
    $response = [
        'success' => true,
        'attributes' => $attributes
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>