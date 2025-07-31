-- Миграция для добавления поля notes в таблицу goods_in_transit
ALTER TABLE goods_in_transit 
    ADD COLUMN notes TEXT NULL COMMENT 'Примечания к товару' AFTER status;
