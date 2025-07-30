-- ============================================================================
-- Миграция: Обновление структуры таблицы requests
-- Дата: 2025-07-30
-- Описание: Добавляем поля для количества, склада, описания и желаемой даты поставки
-- ============================================================================

-- Добавляем новые поля в таблицу requests (сначала как NULL)
ALTER TABLE requests 
ADD COLUMN quantity DECIMAL(10,3) NULL COMMENT 'Желаемое количество' AFTER requested_attributes,
ADD COLUMN warehouse_id INT NULL COMMENT 'ID склада для запроса' AFTER quantity,
ADD COLUMN description TEXT NULL COMMENT 'Описание/комментарий к запросу' AFTER warehouse_id,
ADD COLUMN delivery_date DATE NULL COMMENT 'Желаемая дата поставки' AFTER description;

-- Добавляем индексы для новых полей
ALTER TABLE requests 
ADD INDEX idx_requests_warehouse (warehouse_id),
ADD INDEX idx_requests_delivery_date (delivery_date);

-- Обновляем существующие записи (если есть) - устанавливаем склад и количество по умолчанию
UPDATE requests 
SET 
    warehouse_id = (SELECT id FROM warehouses WHERE status = 1 LIMIT 1),
    quantity = 1
WHERE warehouse_id IS NULL;

-- Теперь делаем поля NOT NULL после заполнения данных
ALTER TABLE requests 
MODIFY COLUMN quantity DECIMAL(10,3) NOT NULL DEFAULT 1,
MODIFY COLUMN warehouse_id INT NOT NULL;

-- Добавляем внешний ключ для склада
ALTER TABLE requests 
ADD FOREIGN KEY (warehouse_id) REFERENCES warehouses(id);