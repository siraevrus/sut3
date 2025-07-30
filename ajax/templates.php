<?php
/**
 * AJAX endpoint для работы с шаблонами
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../config/config.php';

// Проверяем авторизацию
requireAuth();

// Устанавливаем заголовки для JSON
header('Content-Type: application/json; charset=utf-8');

try {
    $action = $_GET['action'] ?? '';
    $template_id = (int)($_GET['template_id'] ?? 0);
    
    $pdo = getDBConnection();
    
    switch ($action) {
        case 'get_attributes':
            if (!$template_id) {
                throw new Exception('Template ID is required');
            }
            
            $stmt = $pdo->prepare("
                SELECT * FROM template_attributes 
                WHERE template_id = ? 
                ORDER BY sort_order, id
            ");
            $stmt->execute([$template_id]);
            $attributes = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => $attributes
            ]);
            break;
            
        case 'calculate_volume':
            if (!$template_id) {
                throw new Exception('Template ID is required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $attributes = $input['attributes'] ?? [];
            
            // Получаем формулу шаблона
            $stmt = $pdo->prepare("SELECT formula FROM product_templates WHERE id = ? AND status = 1");
            $stmt->execute([$template_id]);
            $template = $stmt->fetch();
            
            if (!$template) {
                throw new Exception('Template not found');
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
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'volume' => $volume,
                    'formula' => $template['formula'],
                    'error' => $error
                ]
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    logError('Templates AJAX error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>