-- Начальные данные для системы складского учета (SUT)
-- Версия: 1.0.0
-- Дата создания: 2025-01-29

USE sut_warehouse;

-- ============================================================================
-- СИСТЕМНЫЕ НАСТРОЙКИ
-- ============================================================================
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('system_name', 'Система складского учета', 'Название системы'),
('system_version', '1.0.0', 'Версия системы'),
('default_currency', 'USD', 'Валюта по умолчанию'),
('session_lifetime', '2592000', 'Время жизни сессии в секундах (30 дней)'),
('max_file_size', '10485760', 'Максимальный размер загружаемого файла в байтах (10MB)'),
('allowed_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,odt,rtf', 'Разрешенные типы файлов'),
('items_per_page', '20', 'Количество элементов на странице'),
('backup_enabled', '1', 'Включены ли автоматические бэкапы'),
('maintenance_mode', '0', 'Режим обслуживания (0-выкл, 1-вкл)');

-- ============================================================================
-- СОЗДАНИЕ АДМИНИСТРАТОРА ПО УМОЛЧАНИЮ
-- ============================================================================
-- Пароль: admin123 (обязательно сменить после первого входа!)
INSERT INTO users (
    login, 
    password, 
    first_name, 
    last_name, 
    middle_name, 
    phone, 
    role, 
    company_id, 
    warehouse_id, 
    status
) VALUES (
    'admin',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- admin123
    'Системный',
    'Администратор',
    NULL,
    '+7 (000) 000-00-00',
    'admin',
    NULL,
    NULL,
    1
);

-- ============================================================================
-- ДЕМОНСТРАЦИОННЫЕ КОМПАНИИ
-- ============================================================================
INSERT INTO companies (
    name, 
    legal_address, 
    postal_address, 
    phone, 
    director, 
    email, 
    inn, 
    kpp, 
    ogrn, 
    bank, 
    account, 
    correspondent_account, 
    bik,
    status
) VALUES 
(
    'ООО "Складские решения"',
    '123456, г. Москва, ул. Складская, д. 1',
    '123456, г. Москва, ул. Складская, д. 1',
    '+7 (495) 123-45-67',
    'Иванов Иван Иванович',
    'info@warehouse-solutions.ru',
    '771234567890',
    '123456789',
    '1027739123456',
    'ПАО "Сбербанк России"',
    '40702810123456789012',
    '30101810400000000225',
    '044525225',
    1
),
(
    'ООО "Логистик Плюс"',
    '654321, г. Санкт-Петербург, пр. Логистический, д. 10',
    '654321, г. Санкт-Петербург, пр. Логистический, д. 10',
    '+7 (812) 987-65-43',
    'Петров Петр Петрович',
    'contact@logistic-plus.ru',
    '781234567890',
    '987654321',
    '1027839987654',
    'ВТБ 24 (ПАО)',
    '40702810987654321098',
    '30101810700000000187',
    '044525187',
    1
);

-- ============================================================================
-- ДЕМОНСТРАЦИОННЫЕ СКЛАДЫ
-- ============================================================================
INSERT INTO warehouses (company_id, name, address, status) VALUES
(1, 'Центральный склад', 'г. Москва, ул. Складская, д. 1, стр. 1', 1),
(1, 'Склад №2', 'г. Москва, ул. Складская, д. 1, стр. 2', 1),
(2, 'Основной склад СПб', 'г. Санкт-Петербург, пр. Логистический, д. 10', 1),
(2, 'Временный склад', 'г. Санкт-Петербург, пр. Логистический, д. 12', 1);

-- ============================================================================
-- ДЕМОНСТРАЦИОННЫЕ ПОЛЬЗОВАТЕЛИ
-- ============================================================================
-- Все пароли: password123 (сменить после первого входа!)
INSERT INTO users (
    login, 
    password, 
    first_name, 
    last_name, 
    middle_name, 
    phone, 
    role, 
    company_id, 
    warehouse_id, 
    status
) VALUES 
(
    'operator1',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password123
    'Анна',
    'Операторова',
    'Ивановна',
    '+7 (495) 111-11-11',
    'pc_operator',
    1,
    1,
    1
),
(
    'warehouse1',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password123
    'Сергей',
    'Складской',
    'Петрович',
    '+7 (495) 222-22-22',
    'warehouse_worker',
    1,
    1,
    1
),
(
    'manager1',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password123
    'Елена',
    'Менеджерова',
    'Александровна',
    '+7 (495) 333-33-33',
    'sales_manager',
    1,
    1,
    1
),
(
    'warehouse2',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password123
    'Михаил',
    'Работников',
    'Сергеевич',
    '+7 (812) 444-44-44',
    'warehouse_worker',
    2,
    3,
    1
);

-- ============================================================================
-- ДЕМОНСТРАЦИОННЫЕ ШАБЛОНЫ ТОВАРОВ
-- ============================================================================
INSERT INTO product_templates (name, description, formula, status, created_by) VALUES
(
    'Строительные материалы (блоки)',
    'Шаблон для строительных блоков с расчетом объема',
    'length * width * height',
    1,
    1
),
(
    'Листовые материалы',
    'Шаблон для листовых материалов (фанера, ДСП и т.д.)',
    'length * width * thickness',
    1,
    1
),
(
    'Трубы и профили',
    'Шаблон для труб и металлических профилей',
    '3.14159 * (diameter / 2) * (diameter / 2) * length',
    1,
    1
),
(
    'Сыпучие материалы',
    'Шаблон для сыпучих материалов (песок, щебень и т.д.)',
    'volume',
    1,
    1
);

-- ============================================================================
-- ХАРАКТЕРИСТИКИ ШАБЛОНОВ
-- ============================================================================

-- Характеристики для строительных блоков
INSERT INTO template_attributes (template_id, name, variable, data_type, options, unit, is_required, use_in_formula, sort_order) VALUES
(1, 'Производитель', 'manufacturer', 'select', '["ООО Стройблок", "ЗАО КирпичСтрой", "ИП Блоков", "Другой"]', '', TRUE, FALSE, 1),
(1, 'Материал', 'material', 'select', '["Газобетон", "Пенобетон", "Керамзитобетон", "Силикатный", "Керамический"]', '', TRUE, FALSE, 2),
(1, 'Длина', 'length', 'number', NULL, 'мм', TRUE, TRUE, 3),
(1, 'Ширина', 'width', 'number', NULL, 'мм', TRUE, TRUE, 4),
(1, 'Высота', 'height', 'number', NULL, 'мм', TRUE, TRUE, 5),
(1, 'Марка прочности', 'strength', 'select', '["M25", "M35", "M50", "M75", "M100"]', '', FALSE, FALSE, 6),
(1, 'Количество в партии', 'quantity', 'number', NULL, 'шт', TRUE, FALSE, 7);

-- Характеристики для листовых материалов
INSERT INTO template_attributes (template_id, name, variable, data_type, options, unit, is_required, use_in_formula, sort_order) VALUES
(2, 'Производитель', 'manufacturer', 'select', '["ООО Фанком", "ЗАО ЛистПром", "КДМ-Холдинг", "Другой"]', '', TRUE, FALSE, 1),
(2, 'Тип материала', 'material_type', 'select', '["Фанера", "ДСП", "ДВП", "МДФ", "ОСП"]', '', TRUE, FALSE, 2),
(2, 'Длина', 'length', 'number', NULL, 'мм', TRUE, TRUE, 3),
(2, 'Ширина', 'width', 'number', NULL, 'мм', TRUE, TRUE, 4),
(2, 'Толщина', 'thickness', 'number', NULL, 'мм', TRUE, TRUE, 5),
(2, 'Сорт', 'grade', 'select', '["1 сорт", "2 сорт", "3 сорт", "4 сорт"]', '', FALSE, FALSE, 6),
(2, 'Количество листов', 'quantity', 'number', NULL, 'шт', TRUE, FALSE, 7);

-- Характеристики для труб
INSERT INTO template_attributes (template_id, name, variable, data_type, options, unit, is_required, use_in_formula, sort_order) VALUES
(3, 'Производитель', 'manufacturer', 'select', '["ООО ТрубПром", "ЗАО Металлург", "ТМК", "Другой"]', '', TRUE, FALSE, 1),
(3, 'Тип трубы', 'pipe_type', 'select', '["Стальная", "Чугунная", "ПВХ", "Полипропиленовая", "Медная"]', '', TRUE, FALSE, 2),
(3, 'Диаметр', 'diameter', 'number', NULL, 'мм', TRUE, TRUE, 3),
(3, 'Длина', 'length', 'number', NULL, 'мм', TRUE, TRUE, 4),
(3, 'Толщина стенки', 'wall_thickness', 'number', NULL, 'мм', FALSE, FALSE, 5),
(3, 'ГОСТ', 'gost', 'text', NULL, '', FALSE, FALSE, 6),
(3, 'Количество', 'quantity', 'number', NULL, 'шт', TRUE, FALSE, 7);

-- Характеристики для сыпучих материалов
INSERT INTO template_attributes (template_id, name, variable, data_type, options, unit, is_required, use_in_formula, sort_order) VALUES
(4, 'Производитель', 'manufacturer', 'select', '["ООО СтройМатериалы", "Карьер Центральный", "ГК Стройресурс", "Другой"]', '', TRUE, FALSE, 1),
(4, 'Тип материала', 'material_type', 'select', '["Песок строительный", "Щебень гранитный", "Щебень известняковый", "Керамзит", "Перлит"]', '', TRUE, FALSE, 2),
(4, 'Объем', 'volume', 'number', NULL, 'м³', TRUE, TRUE, 3),
(4, 'Фракция', 'fraction', 'select', '["0-5 мм", "5-10 мм", "10-20 мм", "20-40 мм", "40-70 мм"]', '', FALSE, FALSE, 4),
(4, 'Влажность', 'humidity', 'number', NULL, '%', FALSE, FALSE, 5);

-- ============================================================================
-- ДЕМОНСТРАЦИОННЫЕ ТОВАРЫ
-- ============================================================================
INSERT INTO products (template_id, warehouse_id, arrival_date, transport_number, attributes, calculated_volume, created_by) VALUES
(
    1, 
    1, 
    '2025-01-15', 
    'А123ВС777',
    JSON_OBJECT(
        'manufacturer', 'ООО Стройблок',
        'material', 'Газобетон',
        'length', 600,
        'width', 200,
        'height', 300,
        'strength', 'M35',
        'quantity', 100
    ),
    36.0,
    2
),
(
    2, 
    1, 
    '2025-01-20', 
    'В456СТ123',
    JSON_OBJECT(
        'manufacturer', 'ООО Фанком',
        'material_type', 'Фанера',
        'length', 2440,
        'width', 1220,
        'thickness', 18,
        'grade', '1 сорт',
        'quantity', 50
    ),
    2.688,
    2
),
(
    4, 
    2, 
    '2025-01-18', 
    'С789МН456',
    JSON_OBJECT(
        'manufacturer', 'Карьер Центральный',
        'material_type', 'Песок строительный',
        'volume', 25.5,
        'fraction', '0-5 мм',
        'humidity', 8
    ),
    25.5,
    5
);

-- ============================================================================
-- ОСТАТКИ НА СКЛАДАХ
-- ============================================================================
INSERT INTO inventory (product_id, warehouse_id, template_id, quantity, reserved_quantity) VALUES
(1, 1, 1, 100.0, 0.0),
(2, 1, 2, 50.0, 10.0),
(3, 2, 4, 25.5, 0.0);

-- ============================================================================
-- ДЕМОНСТРАЦИОННЫЕ ЗАПРОСЫ
-- ============================================================================
INSERT INTO requests (template_id, requested_attributes, status, created_by) VALUES
(
    1,
    JSON_OBJECT(
        'manufacturer', 'ООО Стройблок',
        'material', 'Газобетон',
        'length', 600,
        'width', 250,
        'height', 300,
        'strength', 'M50',
        'quantity', 200
    ),
    'pending',
    4
),
(
    2,
    JSON_OBJECT(
        'manufacturer', 'ООО Фанком',
        'material_type', 'ДСП',
        'length', 2800,
        'width', 2070,
        'thickness', 16,
        'grade', '1 сорт',
        'quantity', 30
    ),
    'pending',
    4
);

-- ============================================================================
-- ДЕМОНСТРАЦИОННАЯ ЗАПИСЬ "ТОВАР В ПУТИ"
-- ============================================================================
INSERT INTO goods_in_transit (
    departure_location, 
    departure_date, 
    arrival_date, 
    warehouse_id, 
    goods_info, 
    files, 
    status, 
    created_by
) VALUES
(
    'Завод ООО Стройблок, г. Тула',
    '2025-01-28',
    '2025-01-30',
    1,
    JSON_ARRAY(
        JSON_OBJECT(
            'template_id', 1,
            'attributes', JSON_OBJECT(
                'manufacturer', 'ООО Стройблок',
                'material', 'Газобетон',
                'length', 600,
                'width', 200,
                'height', 250,
                'strength', 'M50',
                'quantity', 150
            )
        )
    ),
    JSON_ARRAY(
        JSON_OBJECT(
            'filename', 'invoice_001.pdf',
            'original_name', 'Накладная №001.pdf',
            'path', 'transit/invoice_001.pdf'
        )
    ),
    'in_transit',
    2
);

-- ============================================================================
-- ДЕМОНСТРАЦИОННАЯ ПРОДАЖА
-- ============================================================================
INSERT INTO sales (
    product_id,
    warehouse_id,
    sale_date,
    buyer,
    quantity,
    price_cashless,
    price_cash,
    exchange_rate,
    total_amount,
    created_by
) VALUES
(
    2,
    1,
    '2025-01-25',
    'ООО СтройКомплект',
    10.0,
    150.00,
    0.00,
    95.50,
    14325.00,
    3
);

-- ============================================================================
-- ОПЕРАЦИЯ СО СКЛАДОМ (ЛОГИРОВАНИЕ ПРОДАЖИ)
-- ============================================================================
INSERT INTO warehouse_operations (
    operation_type,
    product_id,
    warehouse_id,
    quantity_change,
    quantity_before,
    quantity_after,
    reference_id,
    notes,
    created_by
) VALUES
(
    'sale',
    2,
    1,
    -10.0,
    50.0,
    40.0,
    1,
    'Продажа ООО СтройКомплект',
    3
);

-- Обновляем остатки после продажи
UPDATE inventory SET quantity = 40.0, reserved_quantity = 0.0 WHERE product_id = 2;