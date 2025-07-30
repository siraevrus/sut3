# API Документация: Приемка товаров

## Обзор
API для работы с приемкой товаров на складе. Позволяет получать список товаров для приемки, просматривать детали и подтверждать приемку с автоматическим добавлением в остатки склада.

## Базовый URL
```
/api/receiving
```

## Аутентификация
Все endpoints требуют аутентификации через сессию или API токен.

## Права доступа
- **Администратор**: доступ ко всем складам
- **Работник склада**: доступ только к своему складу

---

## Endpoints

### 1. Получить список товаров для приемки

**GET** `/api/receiving`

Возвращает список товаров, готовых к приемке или уже принятых.

#### Параметры запроса
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `warehouse_id` | integer | Нет | ID склада для фильтрации (только для админа) |
| `status` | string | Нет | Статус товаров (`confirmed`, `received`, `arrived`, `in_transit`) |
| `page` | integer | Нет | Номер страницы (по умолчанию: 1) |
| `limit` | integer | Нет | Количество элементов на странице (по умолчанию: 20, максимум: 100) |

#### Пример запроса
```bash
GET /api/receiving?status=confirmed&warehouse_id=1&page=1&limit=10
```

#### Пример ответа
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "departure_date": "2025-01-25 10:00:00",
        "arrival_date": "2025-01-30 14:00:00",
        "departure_location": "Москва",
        "arrival_location": "Санкт-Петербург",
        "status": "confirmed",
        "notes": "Срочная доставка",
        "created_at": "2025-01-25 09:00:00",
        "confirmed_at": null,
        "warehouse_name": "Склад СПб",
        "warehouse_address": "ул. Промышленная, 15",
        "created_by_name": "Иван Петров",
        "created_by_login": "ivan.petrov",
        "confirmed_by_name": null,
        "confirmed_by_login": null,
        "goods_info": [
          {
            "template_id": 1,
            "quantity": 100,
            "unit": "шт",
            "attributes": {
              "manufacturer": "ООО Производитель",
              "model": "Модель-123"
            }
          }
        ],
        "total_quantity": 100,
        "items_count": 1
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 10,
      "total": 5,
      "pages": 1
    }
  }
}
```

---

### 2. Получить детали товара для приемки

**GET** `/api/receiving/{id}`

Возвращает подробную информацию о конкретном товаре в пути.

#### Параметры пути
| Параметр | Тип | Описание |
|----------|-----|----------|
| `id` | integer | ID товара в пути |

#### Пример запроса
```bash
GET /api/receiving/1
```

#### Пример ответа
```json
{
  "success": true,
  "data": {
    "id": 1,
    "departure_date": "2025-01-25 10:00:00",
    "arrival_date": "2025-01-30 14:00:00",
    "departure_location": "Москва",
    "arrival_location": "Санкт-Петербург",
    "warehouse_id": 1,
    "status": "confirmed",
    "notes": "Срочная доставка",
    "created_at": "2025-01-25 09:00:00",
    "created_by": 1,
    "confirmed_at": null,
    "confirmed_by": null,
    "warehouse_name": "Склад СПб",
    "warehouse_address": "ул. Промышленная, 15",
    "created_by_name": "Иван Петров",
    "created_by_login": "ivan.petrov",
    "confirmed_by_name": null,
    "confirmed_by_login": null,
    "goods_info": [
      {
        "template_id": 1,
        "quantity": 100,
        "unit": "шт",
        "attributes": {
          "manufacturer": "ООО Производитель",
          "model": "Модель-123"
        }
      }
    ],
    "files": [
      {
        "original_name": "invoice.pdf",
        "saved_name": "1_invoice_20250125.pdf",
        "size": 245760,
        "type": "application/pdf"
      }
    ]
  }
}
```

---

### 3. Подтвердить приемку товара

**POST** `/api/receiving/confirm`

Подтверждает приемку товара и автоматически добавляет товары в остатки склада.

#### Тело запроса
```json
{
  "transit_id": 1,
  "notes": "Товар принят в полном объеме",
  "damaged_goods": "Повреждений не обнаружено"
}
```

#### Параметры тела запроса
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `transit_id` | integer | Да | ID товара в пути |
| `notes` | string | Нет | Примечания к приемке |
| `damaged_goods` | string | Нет | Описание поврежденных товаров |

#### Пример запроса
```bash
POST /api/receiving/confirm
Content-Type: application/json

{
  "transit_id": 1,
  "notes": "Товар принят в полном объеме",
  "damaged_goods": ""
}
```

#### Пример ответа
```json
{
  "success": true,
  "data": {
    "message": "Receiving confirmed successfully",
    "transit_id": 1,
    "status": "received",
    "warehouse": "Склад СПб",
    "added_items": [
      {
        "inventory_id": 15,
        "template_id": 1,
        "quantity": 100,
        "previous_quantity": 50,
        "new_quantity": 150,
        "action": "updated"
      }
    ]
  }
}
```

---

## Коды статусов товаров

| Статус | Описание |
|--------|----------|
| `in_transit` | Товар в пути |
| `arrived` | Товар прибыл на склад |
| `confirmed` | Товар подтвержден и готов к приемке |
| `received` | Товар принят на склад |

---

## Коды ошибок

### 400 Bad Request
```json
{
  "success": false,
  "error": "Transit ID is required"
}
```

### 403 Forbidden
```json
{
  "success": false,
  "error": "Access denied to this warehouse"
}
```

### 404 Not Found
```json
{
  "success": false,
  "error": "Transit item not found"
}
```

### 500 Internal Server Error
```json
{
  "success": false,
  "error": "Failed to confirm receiving"
}
```

---

## Примеры использования

### Получить товары, готовые к приемке
```bash
curl -X GET "http://localhost:8000/api/receiving?status=confirmed" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Подтвердить приемку товара
```bash
curl -X POST "http://localhost:8000/api/receiving/confirm" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "transit_id": 1,
    "notes": "Товар принят без замечаний"
  }'
```

---

## Примечания

1. **Права доступа**: Работники склада могут работать только с товарами для своего склада
2. **Статусы**: Подтвердить приемку можно только для товаров со статусом `confirmed`
3. **Автоматическое добавление в остатки**: При подтверждении приемки товары автоматически добавляются в таблицу `inventory`
4. **Транзакции**: Все операции выполняются в рамках базы данных транзакций для обеспечения целостности данных
5. **Логирование**: Все ошибки логируются в системный журнал