<?php
/**
 * API Response Helper
 * Система складского учета (SUT)
 */

class ApiResponse {
    
    /**
     * Успешный ответ
     */
    public static function success($data = null, $message = null, $code = 200) {
        http_response_code($code);
        
        $response = [
            'success' => true,
            'timestamp' => date('c'),
        ];
        
        if ($message !== null) {
            $response['message'] = $message;
        }
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Ответ с ошибкой
     */
    public static function error($message, $code = 400, $details = null) {
        http_response_code($code);
        
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $code
            ],
            'timestamp' => date('c'),
        ];
        
        if ($details !== null) {
            $response['error']['details'] = $details;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Ответ с данными и пагинацией
     */
    public static function paginated($data, $total, $page, $limit, $message = null) {
        $totalPages = ceil($total / $limit);
        
        $response = [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'pages' => (int)$totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ],
            'timestamp' => date('c'),
        ];
        
        if ($message !== null) {
            $response['message'] = $message;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Валидационные ошибки
     */
    public static function validationError($errors) {
        self::error('Validation failed', 422, $errors);
    }
    
    /**
     * Ошибка аутентификации
     */
    public static function unauthorized($message = 'Unauthorized') {
        self::error($message, 401);
    }
    
    /**
     * Ошибка доступа
     */
    public static function forbidden($message = 'Access denied') {
        self::error($message, 403);
    }
    
    /**
     * Ресурс не найден
     */
    public static function notFound($message = 'Resource not found') {
        self::error($message, 404);
    }
    
    /**
     * Получение входных данных JSON
     */
    public static function getJsonInput() {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            return [];
        }
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::error('Invalid JSON format', 400);
        }
        
        return $data ?? [];
    }
    
    /**
     * Валидация обязательных полей
     */
    public static function validateRequired($data, $required) {
        $missing = [];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || 
                (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            self::validationError([
                'missing_fields' => $missing,
                'message' => 'Required fields are missing: ' . implode(', ', $missing)
            ]);
        }
    }
    
    /**
     * Получение параметров запроса с значениями по умолчанию
     */
    public static function getQueryParams($defaults = []) {
        $params = [];
        
        foreach ($defaults as $key => $default) {
            $params[$key] = $_GET[$key] ?? $default;
            
            // Преобразование типов
            if (is_int($default)) {
                $params[$key] = (int)$params[$key];
            } elseif (is_bool($default)) {
                $params[$key] = filter_var($params[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        
        return $params;
    }
    
    /**
     * Форматирование даты для API
     */
    public static function formatDate($date) {
        if (!$date) return null;
        
        if (is_string($date)) {
            $date = new DateTime($date);
        }
        
        return $date->format('c'); // ISO 8601
    }
    
    /**
     * Очистка данных для API ответа
     */
    public static function sanitizeData($data) {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Исключаем чувствительные поля
                if (in_array($key, ['password', 'csrf_token', 'session_id'])) {
                    continue;
                }
                
                $sanitized[$key] = self::sanitizeData($value);
            }
            return $sanitized;
        }
        
        if (is_string($data)) {
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }
        
        return $data;
    }
}
?>