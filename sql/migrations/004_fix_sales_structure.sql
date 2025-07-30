-- Миграция: Исправление структуры таблицы sales для работы с остатками
-- Дата: 2025-07-30

USE sut_warehouse;

-- Отключаем проверку внешних ключей для безопасного изменения
SET FOREIGN_KEY_CHECKS = 0;

-- Удаляем старую таблицу sales если она существует
DROP TABLE IF EXISTS sales;

-- Создаем новую таблицу sales для работы с остатками
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT NOT NULL COMMENT 'Склад продажи',
    template_id INT NOT NULL COMMENT 'ID шаблона товара',
    product_attributes_hash VARCHAR(64) NOT NULL COMMENT 'Хеш характеристик товара',
    sale_date DATE NOT NULL COMMENT 'Дата реализации',
    buyer VARCHAR(200) NOT NULL COMMENT 'Покупатель',
    quantity DECIMAL(10,3) NOT NULL COMMENT 'Количество/объем',
    price_cashless DECIMAL(10,2) DEFAULT 0 COMMENT 'Цена продажи без НАЛ ($)',
    price_cash DECIMAL(10,2) DEFAULT 0 COMMENT 'Цена продажи НАЛ ($)',
    exchange_rate DECIMAL(8,4) DEFAULT 1 COMMENT 'Курс валюты (справочно)',
    total_amount DECIMAL(10,2) NOT NULL COMMENT 'Общая сумма продажи (price_cashless + price_cash)',
    created_by INT NOT NULL COMMENT 'Кто создал реализацию',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (template_id) REFERENCES product_templates(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    
    INDEX idx_sales_warehouse (warehouse_id),
    INDEX idx_sales_template (template_id),
    INDEX idx_sales_date (sale_date),
    INDEX idx_sales_created_by (created_by),
    INDEX idx_sales_buyer (buyer),
    INDEX idx_sales_attributes_hash (product_attributes_hash)
) ENGINE=InnoDB COMMENT='Реализация товаров (работа с остатками)';

-- Включаем проверку внешних ключей обратно
SET FOREIGN_KEY_CHECKS = 1;