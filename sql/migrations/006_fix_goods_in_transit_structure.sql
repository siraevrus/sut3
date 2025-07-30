-- Миграция 006: Исправление структуры таблицы goods_in_transit для приемки

-- Добавляем недостающие поля
ALTER TABLE goods_in_transit
ADD COLUMN arrival_location VARCHAR(200) NULL AFTER departure_location,
ADD COLUMN notes TEXT NULL AFTER files;

-- Обновляем ENUM статусов, добавляем 'received'
ALTER TABLE goods_in_transit
MODIFY COLUMN status ENUM('in_transit', 'arrived', 'confirmed', 'received') DEFAULT 'in_transit';

-- Заполняем arrival_location для существующих записей (если есть)
UPDATE goods_in_transit 
SET arrival_location = 'Не указано' 
WHERE arrival_location IS NULL;

-- Делаем arrival_location обязательным
ALTER TABLE goods_in_transit
MODIFY COLUMN arrival_location VARCHAR(200) NOT NULL;