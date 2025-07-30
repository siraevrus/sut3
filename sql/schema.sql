-- Схема базы данных для системы складского учета (SUT)
-- Версия: 1.0.0
-- Дата создания: 2025-01-29

SET FOREIGN_KEY_CHECKS = 0;
DROP DATABASE IF EXISTS sut_warehouse;
CREATE DATABASE sut_warehouse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sut_warehouse;

-- ============================================================================
-- ТАБЛИЦА: Компании
-- ============================================================================
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Название компании',
    legal_address TEXT COMMENT 'Юридический адрес',
    postal_address TEXT COMMENT 'Почтовый адрес',
    phone VARCHAR(50) COMMENT 'Телефон/факс',
    director VARCHAR(100) COMMENT 'Генеральный директор',
    email VARCHAR(100) COMMENT 'Email',
    inn VARCHAR(12) COMMENT 'ИНН',
    kpp VARCHAR(9) COMMENT 'КПП',
    ogrn VARCHAR(13) COMMENT 'ОГРН',
    bank VARCHAR(200) COMMENT 'Банк',
    account VARCHAR(20) COMMENT 'Расчетный счет',
    correspondent_account VARCHAR(20) COMMENT 'Корреспондентский счет',
    bik VARCHAR(9) COMMENT 'БИК',
    status TINYINT DEFAULT 1 COMMENT '1-активна, 0-заблокирована, -1-архивирована',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_companies_status (status),
    INDEX idx_companies_name (name)
) ENGINE=InnoDB COMMENT='Компании';

-- ============================================================================
-- ТАБЛИЦА: Склады
-- ============================================================================
CREATE TABLE warehouses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT 'Название склада',
    address TEXT COMMENT 'Адрес склада',
    status TINYINT DEFAULT 1 COMMENT '1-активен, 0-заблокирован, -1-удален',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_warehouses_company (company_id),
    INDEX idx_warehouses_status (status)
) ENGINE=InnoDB COMMENT='Склады компаний';

-- ============================================================================
-- ТАБЛИЦА: Пользователи
-- ============================================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(50) UNIQUE NOT NULL COMMENT 'Логин для входа',
    password VARCHAR(255) NOT NULL COMMENT 'Хеш пароля',
    first_name VARCHAR(50) NOT NULL COMMENT 'Имя',
    last_name VARCHAR(50) NOT NULL COMMENT 'Фамилия',
    middle_name VARCHAR(50) COMMENT 'Отчество',
    phone VARCHAR(20) COMMENT 'Телефон',
    role ENUM('admin', 'pc_operator', 'warehouse_worker', 'sales_manager') NOT NULL COMMENT 'Роль пользователя',
    company_id INT NULL COMMENT 'ID компании (NULL для администратора)',
    warehouse_id INT NULL COMMENT 'ID склада (NULL для администратора)',
    status TINYINT DEFAULT 1 COMMENT '1-активен, 0-заблокирован',
    blocked_at TIMESTAMP NULL COMMENT 'Дата блокировки',
    last_login TIMESTAMP NULL COMMENT 'Последний вход',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
    INDEX idx_users_login (login),
    INDEX idx_users_role (role),
    INDEX idx_users_company (company_id),
    INDEX idx_users_warehouse (warehouse_id),
    INDEX idx_users_status (status)
) ENGINE=InnoDB COMMENT='Пользователи системы';

-- ============================================================================
-- ТАБЛИЦА: Шаблоны товаров
-- ============================================================================
CREATE TABLE product_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Название шаблона',
    description TEXT COMMENT 'Описание шаблона',
    formula TEXT COMMENT 'Формула расчета объема',
    status TINYINT DEFAULT 1 COMMENT '1-активен, 0-неактивен',
    created_by INT NOT NULL COMMENT 'Кто создал',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_templates_name (name),
    INDEX idx_templates_status (status),
    INDEX idx_templates_created_by (created_by)
) ENGINE=InnoDB COMMENT='Шаблоны товаров с характеристиками';

-- ============================================================================
-- ТАБЛИЦА: Характеристики шаблонов
-- ============================================================================
CREATE TABLE template_attributes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT 'Название характеристики (для пользователя)',
    variable VARCHAR(50) NOT NULL COMMENT 'Переменная для формулы (английские буквы)',
    data_type ENUM('number', 'text', 'select') NOT NULL COMMENT 'Тип данных',
    options TEXT COMMENT 'Варианты для select (JSON)',
    unit VARCHAR(20) COMMENT 'Единица измерения',
    is_required BOOLEAN DEFAULT FALSE COMMENT 'Обязательно к заполнению',
    use_in_formula BOOLEAN DEFAULT FALSE COMMENT 'Участвует в формуле расчета',
    sort_order INT DEFAULT 0 COMMENT 'Порядок отображения',
    
    FOREIGN KEY (template_id) REFERENCES product_templates(id) ON DELETE CASCADE,
    INDEX idx_attributes_template (template_id),
    INDEX idx_attributes_variable (variable),
    UNIQUE KEY uk_template_variable (template_id, variable)
) ENGINE=InnoDB COMMENT='Характеристики шаблонов товаров';

-- ============================================================================
-- ТАБЛИЦА: Товары
-- ============================================================================
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL COMMENT 'ID шаблона товара',
    warehouse_id INT NOT NULL COMMENT 'Склад поступления',
    arrival_date DATE NOT NULL COMMENT 'Дата поступления',
    transport_number VARCHAR(50) COMMENT 'Номер транспортного средства',
    attributes JSON NOT NULL COMMENT 'Значения характеристик товара',
    calculated_volume DECIMAL(10,3) COMMENT 'Рассчитанный объем',
    created_by INT NOT NULL COMMENT 'Кто добавил товар',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (template_id) REFERENCES product_templates(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_products_template (template_id),
    INDEX idx_products_warehouse (warehouse_id),
    INDEX idx_products_arrival_date (arrival_date),
    INDEX idx_products_created_by (created_by)
) ENGINE=InnoDB COMMENT='Товары на складах';

-- ============================================================================
-- ТАБЛИЦА: Остатки товаров
-- ============================================================================
CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL COMMENT 'ID товара',
    warehouse_id INT NOT NULL COMMENT 'ID склада',
    template_id INT NOT NULL COMMENT 'ID шаблона (для быстрых запросов)',
    quantity DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Количество/объем в наличии',
    reserved_quantity DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Зарезервированное количество',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (template_id) REFERENCES product_templates(id),
    UNIQUE KEY uk_inventory_product_warehouse (product_id, warehouse_id),
    INDEX idx_inventory_warehouse (warehouse_id),
    INDEX idx_inventory_template (template_id),
    INDEX idx_inventory_quantity (quantity)
) ENGINE=InnoDB COMMENT='Остатки товаров на складах';

-- ============================================================================
-- ТАБЛИЦА: Запросы
-- ============================================================================
CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL COMMENT 'ID шаблона товара',
    requested_attributes JSON NOT NULL COMMENT 'Запрашиваемые характеристики',
    status ENUM('pending', 'processed') DEFAULT 'pending' COMMENT 'Статус запроса',
    created_by INT NOT NULL COMMENT 'Кто создал запрос',
    processed_by INT NULL COMMENT 'Кто обработал запрос',
    processed_at TIMESTAMP NULL COMMENT 'Дата обработки',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (template_id) REFERENCES product_templates(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (processed_by) REFERENCES users(id),
    INDEX idx_requests_template (template_id),
    INDEX idx_requests_status (status),
    INDEX idx_requests_created_by (created_by),
    INDEX idx_requests_created_at (created_at)
) ENGINE=InnoDB COMMENT='Запросы от сотрудников';

-- ============================================================================
-- ТАБЛИЦА: Товары в пути
-- ============================================================================
CREATE TABLE goods_in_transit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    departure_location VARCHAR(200) NOT NULL COMMENT 'Место отгрузки',
    departure_date DATE NOT NULL COMMENT 'Дата отгрузки',
    arrival_date DATE NOT NULL COMMENT 'Планируемая дата поступления',
    warehouse_id INT NOT NULL COMMENT 'Склад назначения',
    goods_info JSON NOT NULL COMMENT 'Информация о грузе',
    files JSON COMMENT 'Прикрепленные файлы',
    status ENUM('in_transit', 'arrived', 'confirmed') DEFAULT 'in_transit' COMMENT 'Статус доставки',
    created_by INT NOT NULL COMMENT 'Кто создал запись',
    confirmed_by INT NULL COMMENT 'Кто подтвердил получение',
    confirmed_at TIMESTAMP NULL COMMENT 'Дата подтверждения',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (confirmed_by) REFERENCES users(id),
    INDEX idx_transit_warehouse (warehouse_id),
    INDEX idx_transit_status (status),
    INDEX idx_transit_departure_date (departure_date),
    INDEX idx_transit_arrival_date (arrival_date)
) ENGINE=InnoDB COMMENT='Товары в пути';

-- ============================================================================
-- ТАБЛИЦА: Реализация (продажи)
-- ============================================================================
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL COMMENT 'ID товара',
    warehouse_id INT NOT NULL COMMENT 'Склад продажи',
    sale_date DATE NOT NULL COMMENT 'Дата реализации',
    buyer VARCHAR(200) NOT NULL COMMENT 'Покупатель',
    quantity DECIMAL(10,3) NOT NULL COMMENT 'Количество/объем',
    price_cashless DECIMAL(10,2) DEFAULT 0 COMMENT 'Цена продажи без НАЛ ($)',
    price_cash DECIMAL(10,2) DEFAULT 0 COMMENT 'Цена продажи НАЛ ($)',
    exchange_rate DECIMAL(8,4) DEFAULT 1 COMMENT 'Курс валюты',
    total_amount DECIMAL(10,2) NOT NULL COMMENT 'Общая сумма продажи',
    created_by INT NOT NULL COMMENT 'Кто создал реализацию',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_sales_product (product_id),
    INDEX idx_sales_warehouse (warehouse_id),
    INDEX idx_sales_date (sale_date),
    INDEX idx_sales_created_by (created_by)
) ENGINE=InnoDB COMMENT='Реализация товаров';

-- ============================================================================
-- ТАБЛИЦА: Операции со складом (логирование)
-- ============================================================================
CREATE TABLE warehouse_operations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operation_type ENUM('arrival', 'sale', 'transfer', 'adjustment') NOT NULL COMMENT 'Тип операции',
    product_id INT NOT NULL COMMENT 'ID товара',
    warehouse_id INT NOT NULL COMMENT 'ID склада',
    quantity_change DECIMAL(10,3) NOT NULL COMMENT 'Изменение количества (+/-)',
    quantity_before DECIMAL(10,3) NOT NULL COMMENT 'Количество до операции',
    quantity_after DECIMAL(10,3) NOT NULL COMMENT 'Количество после операции',
    reference_id INT COMMENT 'ID связанной записи (sales.id, goods_in_transit.id и т.д.)',
    notes TEXT COMMENT 'Примечания',
    created_by INT NOT NULL COMMENT 'Кто выполнил операцию',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_operations_type (operation_type),
    INDEX idx_operations_product (product_id),
    INDEX idx_operations_warehouse (warehouse_id),
    INDEX idx_operations_date (created_at)
) ENGINE=InnoDB COMMENT='Журнал операций со складом';

-- ============================================================================
-- ТАБЛИЦА: Сессии пользователей
-- ============================================================================
CREATE TABLE user_sessions (
    id VARCHAR(128) PRIMARY KEY COMMENT 'ID сессии',
    user_id INT NOT NULL COMMENT 'ID пользователя',
    ip_address VARCHAR(45) COMMENT 'IP адрес',
    user_agent TEXT COMMENT 'User Agent браузера',
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL COMMENT 'Время истечения сессии',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_expires (expires_at)
) ENGINE=InnoDB COMMENT='Активные сессии пользователей';

-- ============================================================================
-- ТАБЛИЦА: Системные настройки
-- ============================================================================
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY COMMENT 'Ключ настройки',
    setting_value TEXT COMMENT 'Значение настройки',
    description TEXT COMMENT 'Описание настройки',
    updated_by INT COMMENT 'Кто обновил',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB COMMENT='Системные настройки';

-- ============================================================================
-- ПРЕДСТАВЛЕНИЯ (VIEWS)
-- ============================================================================

-- Представление для остатков с детальной информацией
CREATE VIEW v_inventory_details AS
SELECT 
    i.id,
    i.product_id,
    i.warehouse_id,
    i.template_id,
    i.quantity,
    i.reserved_quantity,
    i.quantity - i.reserved_quantity AS available_quantity,
    pt.name AS template_name,
    w.name AS warehouse_name,
    c.name AS company_name,
    p.attributes,
    p.calculated_volume,
    p.arrival_date,
    JSON_EXTRACT(p.attributes, '$.manufacturer') AS manufacturer
FROM inventory i
JOIN products p ON i.product_id = p.id
JOIN product_templates pt ON i.template_id = pt.id
JOIN warehouses w ON i.warehouse_id = w.id
JOIN companies c ON w.company_id = c.id
WHERE i.quantity > 0;

-- Представление для активных запросов
CREATE VIEW v_active_requests AS
SELECT 
    r.id,
    r.template_id,
    r.requested_attributes,
    r.status,
    r.created_by,
    r.created_at,
    pt.name AS template_name,
    CONCAT(u.last_name, ' ', u.first_name, ' ', COALESCE(u.middle_name, '')) AS created_by_name,
    u.role AS created_by_role,
    c.name AS company_name
FROM requests r
JOIN product_templates pt ON r.template_id = pt.id
JOIN users u ON r.created_by = u.id
LEFT JOIN companies c ON u.company_id = c.id
WHERE r.status = 'pending'
ORDER BY r.created_at DESC;

-- Представление для статистики дашборда
CREATE VIEW v_dashboard_stats AS
SELECT 
    (SELECT COUNT(*) FROM companies WHERE status = 1) AS active_companies,
    (SELECT COUNT(*) FROM users WHERE status = 1) AS active_users,
    (SELECT COUNT(*) FROM requests WHERE status = 'pending') AS pending_requests,
    (SELECT COUNT(*) FROM goods_in_transit WHERE status = 'in_transit') AS goods_in_transit,
    (SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date = CURDATE()) AS today_sales_total;

SET FOREIGN_KEY_CHECKS = 1;