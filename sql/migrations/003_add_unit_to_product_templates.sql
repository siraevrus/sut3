-- Миграция: Добавление колонки unit в таблицу product_templates
-- Дата: 2025-07-30
-- Описание: Добавляем колонку для единиц измерения в шаблоны товаров

USE sut_warehouse;

-- Добавляем колонку unit в таблицу product_templates
ALTER TABLE product_templates 
ADD COLUMN unit VARCHAR(20) DEFAULT 'шт.' COMMENT 'Единица измерения';

-- Обновляем единицы измерения для существующих шаблонов
UPDATE product_templates SET unit = 'м²' WHERE name LIKE '%листов%' OR name LIKE '%Листов%';
UPDATE product_templates SET unit = 'кг' WHERE name LIKE '%сыпуч%' OR name LIKE '%Сыпуч%';
UPDATE product_templates SET unit = 'м³' WHERE name LIKE '%объем%' OR name LIKE '%Объем%';
UPDATE product_templates SET unit = 'м' WHERE name LIKE '%труб%' OR name LIKE '%Труб%' OR name LIKE '%профил%' OR name LIKE '%Профил%';

-- Для шаблонов со специфичными единицами измерения
UPDATE product_templates SET unit = 'мм' WHERE id = 1; -- Строительные материалы (блоки)
UPDATE product_templates SET unit = 'м²' WHERE id = 2; -- Листовые материалы  
UPDATE product_templates SET unit = 'м' WHERE id = 3;  -- Трубы и профили
UPDATE product_templates SET unit = 'кг' WHERE id = 4; -- Сыпучие материалы