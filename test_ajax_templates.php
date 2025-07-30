<?php
/**
 * Тест AJAX endpoint для шаблонов
 */

require_once 'config/config.php';

// Имитируем сессию пользователя
session_start();
$_SESSION['user_id'] = 1; // admin
$_SESSION['user_role'] = 'admin';

// Тестируем endpoint
$template_id = 1;
$url = "http://localhost:8000/ajax/templates.php?action=get_attributes&template_id=$template_id";

echo "Тестируем URL: $url\n\n";

// Создаем контекст с cookies для сессии
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Cookie: ' . session_name() . '=' . session_id()
    ]
]);

$response = file_get_contents($url, false, $context);
echo "Ответ сервера:\n";
echo $response . "\n";
?>