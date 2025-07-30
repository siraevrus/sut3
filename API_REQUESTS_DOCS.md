# API Documentation: Requests (Запросы)

## Обзор

API для управления запросами на товары в системе складского учета. Поддерживает полный CRUD функционал с учетом ролевых разрешений.

## Права доступа

- **Администратор**: Полный доступ ко всем запросам, может изменять статусы
- **Менеджер по продажам**: Может просматривать все запросы, создавать и редактировать свои
- **Работник склада**: Может просматривать только свои запросы, создавать и редактировать свои

## Endpoints

### 1. Получить список запросов

```http
GET /api/requests
```

**Параметры запроса:**
- `page` (int, optional) - Номер страницы (по умолчанию: 1)
- `limit` (int, optional) - Количество записей на странице (по умолчанию: 20, максимум: 100)
- `status` (string, optional) - Фильтр по статусу (`pending`, `processed`)
- `template_id` (int, optional) - Фильтр по типу товара
- `warehouse_id` (int, optional) - Фильтр по складу

**Пример запроса:**
```bash
curl -X GET "http://localhost:8000/api/requests?status=pending&page=1&limit=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Ответ (200 OK):**
```json
{
  "success": true,
  "data": {
    "requests": [
      {
        "id": 1,
        "template_id": 2,
        "template_name": "Листовые материалы",
        "warehouse_id": 3,
        "warehouse_name": "Основной склад СПб",
        "company_name": "ООО \"Логистик Плюс\"",
        "quantity": 100.000,
        "delivery_date": "2025-08-15",
        "description": "Срочный заказ для проекта",
        "requested_attributes": {
          "manufacturer": "ДОК Калуга",
          "material": "Фанера",
          "length": 2440,
          "width": 1220,
          "thickness": 18
        },
        "status": "pending",
        "created_by": 5,
        "first_name": "Иван",
        "last_name": "Петров",
        "processed_by": null,
        "processed_at": null,
        "created_at": "2025-07-30 15:30:00"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 10,
      "total": 25,
      "pages": 3
    }
  }
}
```

### 2. Получить конкретный запрос

```http
GET /api/requests/{id}
```

**Пример запроса:**
```bash
curl -X GET "http://localhost:8000/api/requests/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Ответ (200 OK):**
```json
{
  "success": true,
  "data": {
    "request": {
      "id": 1,
      "template_id": 2,
      "template_name": "Листовые материалы",
      "template_description": "Шаблон для листовых материалов",
      "warehouse_id": 3,
      "warehouse_name": "Основной склад СПб",
      "company_name": "ООО \"Логистик Плюс\"",
      "quantity": 100.000,
      "delivery_date": "2025-08-15",
      "description": "Срочный заказ для проекта",
      "requested_attributes": {
        "manufacturer": "ДОК Калуга",
        "material": "Фанера",
        "length": 2440,
        "width": 1220,
        "thickness": 18
      },
      "status": "processed",
      "created_by": 5,
      "first_name": "Иван",
      "last_name": "Петров",
      "creator_role": "warehouse_worker",
      "processed_by": 1,
      "processor_first_name": "Администратор",
      "processor_last_name": "Системы",
      "processed_at": "2025-07-30 16:45:00",
      "created_at": "2025-07-30 15:30:00"
    }
  }
}
```

### 3. Создать новый запрос

```http
POST /api/requests
```

**Тело запроса:**
```json
{
  "template_id": 2,
  "warehouse_id": 3,
  "quantity": 50.5,
  "delivery_date": "2025-08-20",
  "description": "Материалы для ремонта офиса",
  "requested_attributes": {
    "manufacturer": "ДОК Калуга",
    "material": "ДСП",
    "length": 2800,
    "width": 2070,
    "thickness": 16
  }
}
```

**Пример запроса:**
```bash
curl -X POST "http://localhost:8000/api/requests" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "template_id": 2,
    "warehouse_id": 3,
    "quantity": 50.5,
    "delivery_date": "2025-08-20",
    "description": "Материалы для ремонта офиса",
    "requested_attributes": {
      "manufacturer": "ДОК Калуга",
      "material": "ДСП",
      "length": 2800,
      "width": 2070,
      "thickness": 16
    }
  }'
```

**Ответ (201 Created):**
```json
{
  "success": true,
  "data": {
    "request_id": 15
  },
  "message": "Request created successfully"
}
```

### 4. Обновить запрос

```http
PUT /api/requests/{id}
```

**Тело запроса:**
```json
{
  "quantity": 75.0,
  "delivery_date": "2025-08-25",
  "description": "Обновленное описание запроса",
  "requested_attributes": {
    "manufacturer": "ДОК Калуга",
    "material": "ДСП",
    "length": 2800,
    "width": 2070,
    "thickness": 18
  }
}
```

**Пример запроса:**
```bash
curl -X PUT "http://localhost:8000/api/requests/15" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "quantity": 75.0,
    "delivery_date": "2025-08-25",
    "description": "Обновленное описание запроса"
  }'
```

**Ответ (200 OK):**
```json
{
  "success": true,
  "data": [],
  "message": "Request updated successfully"
}
```

### 5. Изменить статус запроса

```http
POST /api/requests/process
```

**Тело запроса:**
```json
{
  "request_id": 15,
  "action": "process"
}
```

**Доступные действия:**
- `process` - Обработать запрос (статус → processed)
- `unprocess` - Вернуть в обработку (статус → pending)

**Пример запроса:**
```bash
curl -X POST "http://localhost:8000/api/requests/process" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "request_id": 15,
    "action": "process"
  }'
```

**Ответ (200 OK):**
```json
{
  "success": true,
  "data": [],
  "message": "Request processed successfully"
}
```

## Коды ошибок

### 400 Bad Request
```json
{
  "success": false,
  "error": "Invalid request ID",
  "code": 400
}
```

### 403 Forbidden
```json
{
  "success": false,
  "error": "Access denied",
  "code": 403
}
```

### 404 Not Found
```json
{
  "success": false,
  "error": "Request not found",
  "code": 404
}
```

### 500 Internal Server Error
```json
{
  "success": false,
  "error": "Failed to fetch requests",
  "code": 500
}
```

## Статусы запросов

- `pending` - Не обработан (по умолчанию)
- `processed` - Обработан

## Ограничения

1. **Создание запросов**: Только роли `warehouse_worker` и `sales_manager`
2. **Редактирование**: Только автор запроса и только если статус `pending`
3. **Изменение статуса**: Только роль `admin`
4. **Просмотр**: 
   - `admin` и `sales_manager` - все запросы
   - `warehouse_worker` - только свои запросы

## Примеры использования

### Получить все необработанные запросы
```bash
curl -X GET "http://localhost:8000/api/requests?status=pending" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Получить запросы по конкретному складу
```bash
curl -X GET "http://localhost:8000/api/requests?warehouse_id=3" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Создать запрос на строительные материалы
```bash
curl -X POST "http://localhost:8000/api/requests" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "template_id": 1,
    "warehouse_id": 2,
    "quantity": 1000,
    "delivery_date": "2025-08-30",
    "description": "Блоки для строительства склада",
    "requested_attributes": {
      "manufacturer": "ЗСК",
      "material": "Газобетон",
      "length": 600,
      "width": 200,
      "height": 300,
      "strength": "M35",
      "quantity": 1000
    }
  }'
```

### Обработать запрос (только для администратора)
```bash
curl -X POST "http://localhost:8000/api/requests/process" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "request_id": 1,
    "action": "process"
  }'
```