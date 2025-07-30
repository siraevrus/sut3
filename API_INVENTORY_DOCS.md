# API Документация - Остатки на складах

## Обзор

API для работы с остатками товаров на складах в системе складского учета (SUT).

Базовый URL: `/api/inventory`

## Аутентификация

Для всех запросов требуется аутентификация через сессию или токен.

## Endpoints

### 1. Получить список остатков

**GET** `/api/inventory`

Возвращает список всех остатков с пагинацией и фильтрацией.

#### Параметры запроса:
- `page` (int, optional) - Номер страницы (по умолчанию: 1)
- `limit` (int, optional) - Количество записей на странице (по умолчанию: 20)
- `warehouse_id` (int, optional) - Фильтр по складу
- `template_id` (int, optional) - Фильтр по шаблону товара
- `search` (string, optional) - Поиск по названию шаблона

#### Пример запроса:
```
GET /api/inventory?page=1&limit=10&warehouse_id=1
```

#### Пример ответа:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "warehouse_id": 1,
      "template_id": 1,
      "template_name": "Строительные блоки",
      "warehouse_name": "Основной склад",
      "company_name": "ООО Стройматериалы",
      "quantity": 150.5,
      "unit": "шт.",
      "product_attributes_hash": "abc123...",
      "last_updated": "2025-07-30T15:30:00+03:00"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total": 25,
    "total_pages": 3
  }
}
```

### 2. Получить остатки по складу

**GET** `/api/inventory/warehouse/{warehouse_id}`

Возвращает все остатки товаров на конкретном складе.

#### Параметры:
- `warehouse_id` (int) - ID склада

#### Пример запроса:
```
GET /api/inventory/warehouse/1
```

#### Пример ответа:
```json
{
  "success": true,
  "data": {
    "warehouse": {
      "id": 1,
      "name": "Основной склад"
    },
    "inventory": [
      {
        "id": 1,
        "template_id": 1,
        "template_name": "Строительные блоки",
        "quantity": 150.5,
        "unit": "шт.",
        "product_attributes_hash": "abc123..."
      }
    ],
    "total_items": 1
  }
}
```

### 3. Получить остатки по шаблону товара

**GET** `/api/inventory/template/{template_id}`

Возвращает остатки конкретного шаблона товара на всех складах.

#### Параметры:
- `template_id` (int) - ID шаблона товара

#### Пример запроса:
```
GET /api/inventory/template/1
```

#### Пример ответа:
```json
{
  "success": true,
  "data": {
    "template": {
      "id": 1,
      "name": "Строительные блоки",
      "unit": "шт."
    },
    "inventory": [
      {
        "id": 1,
        "warehouse_id": 1,
        "warehouse_name": "Основной склад",
        "company_name": "ООО Стройматериалы",
        "quantity": 150.5,
        "product_attributes_hash": "abc123..."
      }
    ],
    "summary": {
      "total_quantity": 150.5,
      "warehouses_count": 1
    }
  }
}
```

### 4. Создать движение товара

**POST** `/api/inventory/movement`

Создает приход или расход товара на складе.

#### Тело запроса:
```json
{
  "warehouse_id": 1,
  "template_id": 1,
  "operation_type": "income",
  "quantity": 25.5,
  "notes": "Поступление товара от поставщика",
  "product_attributes_hash": "abc123..."
}
```

#### Параметры:
- `warehouse_id` (int, required) - ID склада
- `template_id` (int, required) - ID шаблона товара
- `operation_type` (string, required) - Тип операции: "income" (приход) или "outcome" (расход)
- `quantity` (float, required) - Количество (должно быть > 0)
- `notes` (string, required) - Примечания к операции
- `product_attributes_hash` (string, optional) - Хеш атрибутов товара

#### Пример ответа:
```json
{
  "success": true,
  "data": {
    "message": "Inventory movement created successfully",
    "warehouse_id": 1,
    "template_id": 1,
    "operation_type": "income",
    "quantity_change": 25.5,
    "new_quantity": 176.0
  }
}
```

## Коды ошибок

- `400` - Неверные параметры запроса
- `401` - Не авторизован
- `403` - Доступ запрещен
- `404` - Ресурс не найден
- `405` - Метод не поддерживается
- `500` - Внутренняя ошибка сервера

## Пример ошибки:
```json
{
  "success": false,
  "error": {
    "message": "Warehouse not found or inactive",
    "code": 404
  },
  "timestamp": "2025-07-30T15:30:00+03:00"
}
```

## Ограничения доступа

- Пользователи с ролью `admin` имеют доступ ко всем складам
- Пользователи с привязкой к складу имеют доступ только к своему складу
- Все операции логируются в системе

## Примеры использования

### JavaScript (Fetch API)

```javascript
// Получить остатки
const response = await fetch('/api/inventory?page=1&limit=10');
const data = await response.json();

// Создать приход товара
const movement = {
  warehouse_id: 1,
  template_id: 1,
  operation_type: 'income',
  quantity: 50,
  notes: 'Поступление от поставщика'
};

const response = await fetch('/api/inventory/movement', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify(movement)
});
```

### cURL

```bash
# Получить остатки
curl -X GET "http://localhost:8000/api/inventory" \
  -H "Content-Type: application/json"

# Создать движение
curl -X POST "http://localhost:8000/api/inventory/movement" \
  -H "Content-Type: application/json" \
  -d '{
    "warehouse_id": 1,
    "template_id": 1,
    "operation_type": "income",
    "quantity": 25.5,
    "notes": "Тестовое поступление"
  }'
```