-- Миграция для добавления статуса 'received' в таблицу goods_in_transit
ALTER TABLE goods_in_transit 
    MODIFY COLUMN status ENUM('in_transit', 'arrived', 'confirmed', 'received') DEFAULT 'in_transit' COMMENT 'Статус доставки';
