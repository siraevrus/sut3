<?php
/**
 * REST API Router
 * Система складского учета (SUT)
 */

// Устанавливаем заголовки для API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/api_auth.php';
require_once __DIR__ . '/includes/api_response.php';

try {
    // Получаем путь запроса
    $requestUri = $_SERVER['REQUEST_URI'];
    $scriptName = $_SERVER['SCRIPT_NAME'];
    
    // Убираем базовый путь
    $basePath = dirname($scriptName);
    if ($basePath !== '/') {
        $requestUri = substr($requestUri, strlen($basePath));
    }
    
    // Убираем /api/ из пути
    $path = preg_replace('#^/api/?#', '', $requestUri);
    
    // Убираем query string
    $path = strtok($path, '?');
    
    // Разбиваем путь на части
    $pathParts = array_filter(explode('/', $path));
    
    $method = $_SERVER['REQUEST_METHOD'];
    $endpoint = $pathParts[0] ?? '';
    
    // Маршрутизация
    switch ($endpoint) {
        case '':
        case 'info':
            require_once __DIR__ . '/endpoints/info.php';
            break;
            
        case 'auth':
            require_once __DIR__ . '/endpoints/auth.php';
            break;
            
        case 'users':
            require_once __DIR__ . '/endpoints/users.php';
            break;
            
        case 'companies':
            require_once __DIR__ . '/endpoints/companies.php';
            break;
            
        case 'warehouses':
            require_once __DIR__ . '/endpoints/warehouses.php';
            break;
            
        case 'products':
            require_once __DIR__ . '/endpoints/products.php';
            break;
            
        case 'inventory':
            require_once __DIR__ . '/endpoints/inventory.php';
            break;
            
        case 'requests':
            require_once __DIR__ . '/endpoints/requests.php';
            break;
            
        case 'sales':
            require_once __DIR__ . '/endpoints/sales.php';
            break;
            
        case 'transit':
            require_once __DIR__ . '/endpoints/transit.php';
            break;
            
        case 'templates':
            require_once __DIR__ . '/endpoints/templates.php';
            break;
            
        default:
            ApiResponse::error('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    logError('API Error: ' . $e->getMessage(), [
        'endpoint' => $endpoint ?? 'unknown',
        'method' => $method ?? 'unknown',
        'path' => $path ?? 'unknown'
    ]);
    
    ApiResponse::error('Internal server error', 500);
}
?>