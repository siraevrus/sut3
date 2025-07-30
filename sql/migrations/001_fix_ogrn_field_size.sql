-- Миграция: Исправление размера поля ОГРН
-- Дата: 2025-07-30
-- Описание: Увеличение размера поля ogrn с 13 до 15 символов для поддержки ОГРНИП

-- Изменяем размер поля ОГРН
ALTER TABLE companies MODIFY COLUMN ogrn VARCHAR(15) COMMENT 'ОГРН';

-- Проверяем результат
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'sut_warehouse' 
  AND TABLE_NAME = 'companies' 
  AND COLUMN_NAME = 'ogrn';