-- Миграция: Исправление структуры таблицы inventory
-- Дата: 2025-01-30
-- Описание: Убираем product_id из inventory, остатки должны быть агрегированными

-- Удаляем внешний ключ и индекс, связанные с product_id
ALTER TABLE inventory DROP FOREIGN KEY inventory_ibfk_1;
ALTER TABLE inventory DROP INDEX uk_inventory_product_warehouse;

-- Удаляем поле product_id
ALTER TABLE inventory DROP COLUMN product_id;

-- Создаем новый уникальный ключ для warehouse_id + template_id
ALTER TABLE inventory ADD UNIQUE KEY uk_inventory_warehouse_template (warehouse_id, template_id);

-- Добавляем индекс для быстрого поиска по шаблону и складу
ALTER TABLE inventory ADD INDEX idx_inventory_warehouse_template (warehouse_id, template_id);