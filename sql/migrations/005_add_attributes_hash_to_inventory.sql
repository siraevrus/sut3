-- Миграция: Добавление product_attributes_hash в таблицу inventory
-- Дата: 2025-07-30

USE sut_warehouse;

-- Добавляем колонку product_attributes_hash
ALTER TABLE inventory 
ADD COLUMN product_attributes_hash VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Хеш характеристик товара';

-- Для существующих записей создаем хеш из пустого JSON объекта
UPDATE inventory 
SET product_attributes_hash = SHA2('{}', 256) 
WHERE product_attributes_hash = '';

-- Добавляем индекс
ALTER TABLE inventory 
ADD INDEX idx_inventory_attributes_hash (product_attributes_hash);

-- Добавляем уникальный ключ для предотвращения дублирования
ALTER TABLE inventory 
ADD UNIQUE KEY uk_inventory_warehouse_template_attributes (warehouse_id, template_id, product_attributes_hash);