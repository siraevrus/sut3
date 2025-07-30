# API Documentation: Товар в пути (Transit)

## Обзор

API для управления товарами в пути предоставляет полный CRUD функционал для отслеживания транспортировки товаров между складами.

## Базовый URL

```
/api/transit
```

## Аутентификация

Все запросы требуют аутентификации через:
- Bearer токен в заголовке `Authorization: Bearer {token}`
- Или активную сессию пользователя

## Права доступа

- **Администратор**: полный доступ ко всем отправкам
- **Оператор ПК**: полный доступ ко всем отправкам
- **Работник склада**: доступ только к отправкам своего склада
- **Менеджер по продажам**: полный доступ ко всем отправкам

## Endpoints

### 1. Получить список отправок

```http
GET /api/transit
```

#### Параметры запроса

| Параметр | Тип | Описание | По умолчанию |
|----------|-----|----------|--------------|
| `warehouse_id` | integer | ID склада для фильтрации | - |
| `status` | string | Статус отправки (`in_transit`, `arrived`, `confirmed`) | - |
| `page` | integer | Номер страницы | 1 |
| `limit` | integer | Количество записей на странице (1-100) | 20 |

#### Пример запроса

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     "http://localhost:8000/api/transit?status=in_transit&page=1&limit=10"
```

#### Ответ

```json
{
    "success": true,
    "data": {
        "items": [
            {
                "id": 1,
                "departure_location": "Москва, склад поставщика",
                "departure_date": "2025-07-25",
                "arrival_date": "2025-07-30",
                "status": "in_transit",
                "warehouse_name": "Основной склад",
                "company_name": "ООО Торговая компания",
                "created_by_name": "Иван Иванов",
                "confirmed_by_name": null,
                "goods_count": 3,
                "files_count": 2,
                "created_at": "2025-07-25 10:30:00",
                "confirmed_at": null
            }
        ],
        "pagination": {
            "current_page": 1,
            "total_pages": 5,
            "total_records": 95,
            "per_page": 20
        }
    },
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

### 2. Получить конкретную отправку

```http
GET /api/transit/{id}
```

#### Параметры

| Параметр | Тип | Описание |
|----------|-----|----------|
| `id` | integer | ID отправки |

#### Пример запроса

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     "http://localhost:8000/api/transit/1"
```

#### Ответ

```json
{
    "success": true,
    "data": {
        "id": 1,
        "departure_location": "Москва, склад поставщика",
        "departure_date": "2025-07-25",
        "arrival_date": "2025-07-30",
        "warehouse_id": 1,
        "status": "in_transit",
        "created_by": 1,
        "confirmed_by": null,
        "confirmed_at": null,
        "created_at": "2025-07-25 10:30:00",
        "updated_at": "2025-07-25 10:30:00",
        "warehouse_name": "Основной склад",
        "warehouse_address": "г. Санкт-Петербург, ул. Складская, 1",
        "company_name": "ООО Торговая компания",
        "created_by_name": "Иван Иванов",
        "confirmed_by_name": null,
        "goods_info": [
            {
                "template_id": 1,
                "quantity": 100.5,
                "attributes": {
                    "length": "2.5",
                    "width": "1.2",
                    "material": "Сталь"
                }
            }
        ],
        "files": [
            {
                "original_name": "накладная.pdf",
                "file_name": "67890_1722348600.pdf",
                "file_path": "uploads/transit/67890_1722348600.pdf",
                "file_size": 245760,
                "uploaded_at": "2025-07-25 10:30:00"
            }
        ]
    },
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

### 3. Создать новую отправку

```http
POST /api/transit
```

#### Тело запроса

```json
{
    "departure_location": "Москва, склад поставщика",
    "departure_date": "2025-07-25",
    "arrival_date": "2025-07-30",
    "warehouse_id": 1,
    "goods_info": [
        {
            "template_id": 1,
            "quantity": 100.5,
            "attributes": {
                "length": "2.5",
                "width": "1.2",
                "material": "Сталь"
            }
        }
    ],
    "files": [
        {
            "original_name": "накладная.pdf",
            "file_name": "67890_1722348600.pdf",
            "file_path": "uploads/transit/67890_1722348600.pdf",
            "file_size": 245760,
            "uploaded_at": "2025-07-25 10:30:00"
        }
    ]
}
```

#### Пример запроса

```bash
curl -X POST \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"departure_location":"Москва","departure_date":"2025-07-25","arrival_date":"2025-07-30","warehouse_id":1,"goods_info":[{"template_id":1,"quantity":100.5,"attributes":{"material":"Сталь"}}]}' \
     "http://localhost:8000/api/transit"
```

#### Ответ

```json
{
    "success": true,
    "data": {
        "id": 15
    },
    "message": "Transit item created successfully",
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

### 4. Обновить отправку

```http
PUT /api/transit/{id}
```

#### Параметры

| Параметр | Тип | Описание |
|----------|-----|----------|
| `id` | integer | ID отправки |

#### Тело запроса

Поддерживаются частичные обновления. Можно передать только изменяемые поля:

```json
{
    "departure_location": "Новое место отгрузки",
    "arrival_date": "2025-08-01",
    "goods_info": [
        {
            "template_id": 1,
            "quantity": 150.0,
            "attributes": {
                "material": "Алюминий"
            }
        }
    ]
}
```

#### Пример запроса

```bash
curl -X PUT \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"departure_location":"Новое место"}' \
     "http://localhost:8000/api/transit/1"
```

#### Ответ

```json
{
    "success": true,
    "data": {
        "id": 1
    },
    "message": "Transit item updated successfully",
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

### 5. Обновить статус отправки

```http
POST /api/transit/status
```

#### Тело запроса

```json
{
    "id": 1,
    "status": "arrived"
}
```

#### Доступные статусы и переходы

| Текущий статус | Возможные переходы |
|----------------|-------------------|
| `in_transit` | `arrived` |
| `arrived` | `confirmed` |
| `confirmed` | - (нельзя изменить) |

#### Пример запроса

```bash
curl -X POST \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"id":1,"status":"arrived"}' \
     "http://localhost:8000/api/transit/status"
```

#### Ответ

```json
{
    "success": true,
    "data": {
        "id": 1
    },
    "message": "Status updated successfully",
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

### 6. Удалить отправку

```http
DELETE /api/transit/{id}
```

#### Параметры

| Параметр | Тип | Описание |
|----------|-----|----------|
| `id` | integer | ID отправки |

#### Ограничения

- Нельзя удалить отправки со статусом `confirmed`
- При удалении автоматически удаляются связанные файлы

#### Пример запроса

```bash
curl -X DELETE \
     -H "Authorization: Bearer YOUR_TOKEN" \
     "http://localhost:8000/api/transit/1"
```

#### Ответ

```json
{
    "success": true,
    "data": [],
    "message": "Transit item deleted successfully",
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

## Коды ошибок

| Код | Описание |
|-----|----------|
| 400 | Неверные параметры запроса |
| 401 | Требуется аутентификация |
| 403 | Доступ запрещен |
| 404 | Отправка не найдена |
| 405 | Метод не поддерживается |
| 500 | Внутренняя ошибка сервера |

## Примеры ошибок

### Отправка не найдена

```json
{
    "success": false,
    "error": {
        "message": "Transit item not found",
        "code": 404
    },
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

### Недостаточно прав

```json
{
    "success": false,
    "error": {
        "message": "Access denied to selected warehouse",
        "code": 403
    },
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

### Неверный переход статуса

```json
{
    "success": false,
    "error": {
        "message": "Invalid status transition",
        "code": 400
    },
    "timestamp": "2025-07-30T15:00:00+03:00"
}
```

## Структура данных

### Объект отправки (Transit Item)

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | integer | Уникальный идентификатор |
| `departure_location` | string | Место отгрузки |
| `departure_date` | date | Дата отгрузки (YYYY-MM-DD) |
| `arrival_date` | date | Планируемая дата поступления |
| `warehouse_id` | integer | ID склада назначения |
| `status` | enum | Статус (`in_transit`, `arrived`, `confirmed`) |
| `goods_info` | array | Информация о товарах в грузе |
| `files` | array | Прикрепленные файлы |
| `created_by` | integer | ID создавшего пользователя |
| `confirmed_by` | integer | ID подтвердившего пользователя |
| `created_at` | timestamp | Дата создания |
| `updated_at` | timestamp | Дата последнего обновления |
| `confirmed_at` | timestamp | Дата подтверждения |

### Объект товара в грузе

| Поле | Тип | Описание |
|------|-----|----------|
| `template_id` | integer | ID шаблона товара |
| `quantity` | float | Количество |
| `attributes` | object | Характеристики товара |

### Объект файла

| Поле | Тип | Описание |
|------|-----|----------|
| `original_name` | string | Исходное имя файла |
| `file_name` | string | Имя файла на сервере |
| `file_path` | string | Путь к файлу |
| `file_size` | integer | Размер файла в байтах |
| `uploaded_at` | timestamp | Дата загрузки |

## Примечания

1. **Права доступа**: Работники склада видят только отправки для своего склада
2. **Статусы**: Переходы между статусами строго контролируются
3. **Файлы**: При удалении отправки автоматически удаляются связанные файлы
4. **Валидация**: Все входящие данные проходят валидацию на сервере
5. **Транзакции**: Критические операции выполняются в транзакциях