<?php
/**
 * API Info Endpoint
 * Система складского учета (SUT)
 */

if ($method !== 'GET') {
    ApiResponse::error('Method not allowed', 405);
}

// Публичная информация о API
$info = [
    'name' => APP_NAME . ' API',
    'version' => APP_VERSION,
    'description' => 'REST API для системы складского учета',
    'timestamp' => date('c'),
    'endpoints' => [
        'auth' => [
            'POST /api/auth/login' => 'Аутентификация пользователя',
            'POST /api/auth/logout' => 'Выход из системы',
            'GET /api/auth/me' => 'Информация о текущем пользователе'
        ],
        'users' => [
            'GET /api/users' => 'Список пользователей (admin)',
            'GET /api/users/{id}' => 'Информация о пользователе',
            'POST /api/users' => 'Создание пользователя (admin)',
            'PUT /api/users/{id}' => 'Обновление пользователя',
            'DELETE /api/users/{id}' => 'Удаление пользователя (admin)'
        ],
        'companies' => [
            'GET /api/companies' => 'Список компаний',
            'GET /api/companies/{id}' => 'Информация о компании',
            'POST /api/companies' => 'Создание компании (admin)',
            'PUT /api/companies/{id}' => 'Обновление компании (admin)',
            'DELETE /api/companies/{id}' => 'Архивирование компании (admin)'
        ],
        'warehouses' => [
            'GET /api/warehouses' => 'Список складов',
            'GET /api/warehouses/{id}' => 'Информация о складе',
            'POST /api/warehouses' => 'Создание склада',
            'PUT /api/warehouses/{id}' => 'Обновление склада',
            'DELETE /api/warehouses/{id}' => 'Удаление склада'
        ],
        'products' => [
            'GET /api/products' => 'Список товаров',
            'GET /api/products/{id}' => 'Информация о товаре',
            'POST /api/products' => 'Добавление товара',
            'PUT /api/products/{id}' => 'Обновление товара',
            'DELETE /api/products/{id}' => 'Удаление товара'
        ],
        'inventory' => [
            'GET /api/inventory' => 'Остатки на складах',
            'GET /api/inventory/warehouse/{id}' => 'Остатки конкретного склада',
            'GET /api/inventory/product/{id}' => 'Остатки конкретного товара'
        ],
        'requests' => [
            'GET /api/requests' => 'Список запросов',
            'GET /api/requests/{id}' => 'Информация о запросе',
            'POST /api/requests' => 'Создание запроса',
            'PUT /api/requests/{id}' => 'Обновление статуса запроса'
        ],
        'sales' => [
            'GET /api/sales' => 'Список продаж',
            'GET /api/sales/{id}' => 'Информация о продаже',
            'POST /api/sales' => 'Создание продажи'
        ],
        'transit' => [
            'GET /api/transit' => 'Товары в пути',
            'GET /api/transit/{id}' => 'Информация о доставке',
            'POST /api/transit' => 'Создание записи о доставке',
            'PUT /api/transit/{id}' => 'Обновление статуса доставки'
        ],
        'templates' => [
            'GET /api/templates' => 'Шаблоны товаров',
            'GET /api/templates/{id}' => 'Информация о шаблоне',
            'POST /api/templates' => 'Создание шаблона (admin)',
            'PUT /api/templates/{id}' => 'Обновление шаблона (admin)',
            'DELETE /api/templates/{id}' => 'Удаление шаблона (admin)'
        ]
    ],
    'authentication' => [
        'type' => 'Bearer Token',
        'header' => 'Authorization: Bearer {token}',
        'parameter' => 'token={token}',
        'expires' => '30 days'
    ],
    'response_format' => [
        'success' => [
            'success' => true,
            'data' => '...',
            'message' => 'Optional message',
            'timestamp' => 'ISO 8601 timestamp'
        ],
        'error' => [
            'success' => false,
            'error' => [
                'message' => 'Error description',
                'code' => 'HTTP status code',
                'details' => 'Optional error details'
            ],
            'timestamp' => 'ISO 8601 timestamp'
        ],
        'paginated' => [
            'success' => true,
            'data' => '...',
            'pagination' => [
                'total' => 'Total items',
                'page' => 'Current page',
                'limit' => 'Items per page',
                'pages' => 'Total pages',
                'has_next' => 'Boolean',
                'has_prev' => 'Boolean'
            ],
            'timestamp' => 'ISO 8601 timestamp'
        ]
    ],
    'status_codes' => [
        200 => 'OK - Успешный запрос',
        201 => 'Created - Ресурс создан',
        400 => 'Bad Request - Неверный запрос',
        401 => 'Unauthorized - Требуется аутентификация',
        403 => 'Forbidden - Доступ запрещен',
        404 => 'Not Found - Ресурс не найден',
        405 => 'Method Not Allowed - Метод не разрешен',
        422 => 'Unprocessable Entity - Ошибки валидации',
        500 => 'Internal Server Error - Внутренняя ошибка сервера'
    ],
    'rate_limiting' => [
        'enabled' => false,
        'note' => 'В будущих версиях будет добавлено ограничение запросов'
    ],
    'versioning' => [
        'current' => 'v1',
        'note' => 'Версионирование API будет добавлено в будущих релизах'
    ]
];

ApiResponse::success($info, 'API Information');
?>