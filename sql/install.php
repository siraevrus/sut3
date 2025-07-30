<?php
/**
 * Скрипт установки базы данных
 * Система складского учета (SUT)
 */

// Проверяем, что скрипт запускается из командной строки или локально
if (php_sapi_name() !== 'cli' && 
    !in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'])) {
    die('Доступ запрещен');
}

echo "=== Установка базы данных для Системы складского учета ===\n\n";

// Подключаем конфигурацию
require_once __DIR__ . '/../config/database.php';

try {
    echo "1. Подключение к MySQL серверу...\n";
    
    // Подключаемся к серверу без указания базы данных
    $dsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);
    
    echo "   ✓ Подключение установлено\n\n";
    
    echo "2. Создание базы данных...\n";
    
    // Проверяем, существует ли база данных
    $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
    $stmt->execute([DB_NAME]);
    
    if ($stmt->fetch()) {
        echo "   ! База данных '" . DB_NAME . "' уже существует\n";
        
        if (php_sapi_name() === 'cli') {
            echo "   Пересоздать базу данных? (y/N): ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);
            
            if (trim(strtolower($line)) !== 'y') {
                echo "   Установка отменена\n";
                exit(0);
            }
        } else {
            // Для веб-интерфейса - запрашиваем подтверждение через GET параметр
            if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
                echo "   <a href='?confirm=yes'>Нажмите здесь для пересоздания базы данных</a>\n";
                exit(0);
            }
        }
        
        echo "   Удаление существующей базы данных...\n";
        $pdo->exec("DROP DATABASE " . DB_NAME);
    }
    
    // Создаем базу данных
    $pdo->exec("CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "   ✓ База данных '" . DB_NAME . "' создана\n\n";
    
    echo "3. Создание структуры таблиц...\n";
    
    // Подключаемся к созданной базе данных
    $pdo = null; // Закрываем старое соединение
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, DB_OPTIONS);
    
    // Читаем и выполняем схему
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Файл схемы не найден: " . $schemaFile);
    }
    
    $schema = file_get_contents($schemaFile);
    
    // Удаляем команды создания/удаления базы данных из схемы
    $schema = preg_replace('/^(SET FOREIGN_KEY_CHECKS = 0;|DROP DATABASE.*?;|CREATE DATABASE.*?;|USE.*?;)$/m', '', $schema);
    
    // Разбиваем на отдельные запросы
    $queries = array_filter(array_map('trim', explode(';', $schema)));
    
    $tableCount = 0;
    foreach ($queries as $query) {
        if (empty($query) || strpos($query, '--') === 0) continue;
        
        $pdo->exec($query);
        
        if (stripos($query, 'CREATE TABLE') === 0) {
            $tableCount++;
            preg_match('/CREATE TABLE\s+(\w+)/i', $query, $matches);
            echo "   ✓ Таблица '{$matches[1]}' создана\n";
        } elseif (stripos($query, 'CREATE VIEW') === 0) {
            preg_match('/CREATE VIEW\s+(\w+)/i', $query, $matches);
            echo "   ✓ Представление '{$matches[1]}' создано\n";
        }
    }
    
    echo "   Всего создано таблиц: $tableCount\n\n";
    
    echo "4. Загрузка начальных данных...\n";
    
    // Читаем и выполняем начальные данные
    $dataFile = __DIR__ . '/initial_data.sql';
    if (!file_exists($dataFile)) {
        throw new Exception("Файл с начальными данными не найден: " . $dataFile);
    }
    
    $data = file_get_contents($dataFile);
    
    // Разбиваем на отдельные запросы
    $queries = array_filter(array_map('trim', explode(';', $data)));
    
    $insertCount = 0;
    foreach ($queries as $query) {
        if (empty($query) || strpos($query, '--') === 0 || stripos($query, 'USE ') === 0) continue;
        
        $pdo->exec($query);
        
        if (stripos($query, 'INSERT INTO') === 0) {
            $insertCount++;
        }
    }
    
    echo "   ✓ Выполнено $insertCount INSERT запросов\n\n";
    
    echo "5. Проверка установки...\n";
    
    // Проверяем количество таблиц
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'");
    $tableCount = $stmt->fetchColumn();
    echo "   ✓ Создано таблиц: $tableCount\n";
    
    // Проверяем админа
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $adminCount = $stmt->fetchColumn();
    echo "   ✓ Создано администраторов: $adminCount\n";
    
    // Проверяем шаблоны товаров
    $stmt = $pdo->query("SELECT COUNT(*) FROM product_templates");
    $templateCount = $stmt->fetchColumn();
    echo "   ✓ Создано шаблонов товаров: $templateCount\n";
    
    // Проверяем компании
    $stmt = $pdo->query("SELECT COUNT(*) FROM companies");
    $companyCount = $stmt->fetchColumn();
    echo "   ✓ Создано компаний: $companyCount\n";
    
    echo "\n=== УСТАНОВКА ЗАВЕРШЕНА УСПЕШНО ===\n\n";
    
    echo "Данные для входа в систему:\n";
    echo "Логин: admin\n";
    echo "Пароль: admin123\n";
    echo "\n⚠️  ОБЯЗАТЕЛЬНО СМЕНИТЕ ПАРОЛЬ ПОСЛЕ ПЕРВОГО ВХОДА!\n\n";
    
    echo "Демонстрационные пользователи:\n";
    echo "- operator1 / password123 (Оператор ПК)\n";
    echo "- warehouse1 / password123 (Работник склада)\n";
    echo "- manager1 / password123 (Менеджер по продажам)\n";
    echo "- warehouse2 / password123 (Работник склада СПб)\n\n";
    
    echo "База данных готова к использованию!\n";
    
} catch (PDOException $e) {
    echo "❌ Ошибка базы данных: " . $e->getMessage() . "\n";
    echo "\nПроверьте настройки подключения в config/database.php\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>