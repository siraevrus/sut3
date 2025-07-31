-- Миграция для исправления структуры таблицы goods_in_transit
-- Удаляем поле arrival_location и делаем arrival_date nullable

-- Удаляем поле arrival_location
ALTER TABLE goods_in_transit DROP COLUMN arrival_location;

-- Делаем поле arrival_date nullable
ALTER TABLE goods_in_transit MODIFY COLUMN arrival_date date NULL; 