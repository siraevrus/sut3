<?php
/**
 * Templates API Endpoint
 * Система складского учета (SUT)
 */

ApiAuth::requireAuth();

// Разбираем путь
$pathParts = array_filter(explode('/', $path));
$templateId = isset($pathParts[1]) ? (int)$pathParts[1] : 0;
$action = isset($pathParts[2]) ? $pathParts[2] : '';

try {
    $pdo = getDBConnection();
    
    switch ($method) {
        case 'GET':
            if ($templateId && $action === 'attributes') {
                // GET /api/templates/{id}/attributes - получение атрибутов шаблона
                $stmt = $pdo->prepare("
                    SELECT * FROM template_attributes 
                    WHERE template_id = ? 
                    ORDER BY sort_order, id
                ");
                $stmt->execute([$templateId]);
                $attributes = $stmt->fetchAll();
                
                ApiResponse::success($attributes, 'Атрибуты шаблона получены');
                
            } elseif ($templateId) {
                // GET /api/templates/{id} - получение шаблона
                $stmt = $pdo->prepare("
                    SELECT pt.*, u.first_name, u.last_name
                    FROM product_templates pt
                    LEFT JOIN users u ON pt.created_by = u.id
                    WHERE pt.id = ?
                ");
                $stmt->execute([$templateId]);
                $template = $stmt->fetch();
                
                if (!$template) {
                    ApiResponse::error('Шаблон не найден', 404);
                }
                
                ApiResponse::success($template, 'Шаблон получен');
                
            } else {
                // GET /api/templates - список шаблонов
                $stmt = $pdo->query("
                    SELECT pt.*, u.first_name, u.last_name
                    FROM product_templates pt
                    LEFT JOIN users u ON pt.created_by = u.id
                    WHERE pt.status = 1
                    ORDER BY pt.name
                ");
                $templates = $stmt->fetchAll();
                
                ApiResponse::success($templates, 'Список шаблонов получен');
            }
            break;
            
        case 'POST':
            if ($templateId && $action === 'calculate') {
                // POST /api/templates/{id}/calculate - расчет объема
                $input = json_decode(file_get_contents('php://input'), true);
                $attributes = $input['attributes'] ?? [];
                
                // Получаем шаблон
                $stmt = $pdo->prepare("SELECT formula FROM product_templates WHERE id = ? AND status = 1");
                $stmt->execute([$templateId]);
                $template = $stmt->fetch();
                
                if (!$template) {
                    ApiResponse::error('Шаблон не найден', 404);
                }
                
                $volume = null;
                $error = null;
                
                if (!empty($template['formula']) && !empty($attributes)) {
                    try {
                        $volume = calculateVolumeByFormula($template['formula'], $attributes);
                    } catch (Exception $e) {
                        $error = $e->getMessage();
                    }
                }
                
                ApiResponse::success([
                    'volume' => $volume,
                    'formula' => $template['formula'],
                    'attributes' => $attributes,
                    'error' => $error
                ], $volume !== null ? 'Объем рассчитан' : 'Не удалось рассчитать объем');
                
            } else {
                ApiResponse::error('Неподдерживаемый метод', 405);
            }
            break;
            
        default:
            ApiResponse::error('Неподдерживаемый метод', 405);
    }
    
} catch (Exception $e) {
    logError('Templates API error: ' . $e->getMessage());
    ApiResponse::error('Внутренняя ошибка сервера', 500);
}
?>