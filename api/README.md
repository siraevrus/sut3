# REST API - Система складского учета

## Обзор

REST API для системы складского учета предоставляет программный доступ ко всем функциям системы для мобильных приложений и интеграций.

## Базовый URL

```
http://your-domain.com/api/
```

## Аутентификация

API использует Bearer Token аутентификацию. Токены имеют срок действия 30 дней.

### Получение токена

```http
POST /api/auth/login
Content-Type: application/json

{
    "login": "admin",
    "password": "admin123",
    "device_info": {
        "device_type": "mobile",
        "device_name": "iPhone 15",
        "app_version": "1.0.0"
    }
}
```

**Ответ:**
```json
{
    "success": true,
    "data": {
        "token": "eyJ1c2VyX2lkIjoxLCJpc3N1ZWRfYXQiOjE3MDY1...",
        "token_type": "Bearer",
        "expires_in": 2592000,
        "user": {
            "id": 1,
            "login": "admin",
            "first_name": "Системный",
            "last_name": "Администратор",
            "role": "admin"
        }
    },
    "message": "Login successful",
    "timestamp": "2025-01-29T10:30:00+00:00"
}
```

### Использование токена

Включите токен в заголовок Authorization:

```http
Authorization: Bearer eyJ1c2VyX2lkIjoxLCJpc3N1ZWRfYXQiOjE3MDY1...
```

Или как параметр запроса:
```http
GET /api/users?token=eyJ1c2VyX2lkIjoxLCJpc3N1ZWRfYXQiOjE3MDY1...
```

## Формат ответов

### Успешный ответ
```json
{
    "success": true,
    "data": {...},
    "message": "Optional message",
    "timestamp": "2025-01-29T10:30:00+00:00"
}
```

### Ответ с ошибкой
```json
{
    "success": false,
    "error": {
        "message": "Error description",
        "code": 400,
        "details": {...}
    },
    "timestamp": "2025-01-29T10:30:00+00:00"
}
```

### Пагинированный ответ
```json
{
    "success": true,
    "data": [...],
    "pagination": {
        "total": 100,
        "page": 1,
        "limit": 20,
        "pages": 5,
        "has_next": true,
        "has_prev": false
    },
    "timestamp": "2025-01-29T10:30:00+00:00"
}
```

## Endpoints

### Информация о API

#### GET /api/info
Получение информации о API (не требует аутентификации)

### Аутентификация

#### POST /api/auth/login
Аутентификация пользователя

**Параметры:**
- `login` (string, required) - Логин пользователя
- `password` (string, required) - Пароль
- `device_info` (object, optional) - Информация об устройстве

#### POST /api/auth/logout
Выход из системы (отзыв токена)

#### GET /api/auth/me
Получение информации о текущем пользователе

#### POST /api/auth/refresh
Обновление токена

### Пользователи

#### GET /api/users
Получение списка пользователей (только для админов)

**Параметры запроса:**
- `page` (int) - Номер страницы (по умолчанию: 1)
- `limit` (int) - Количество записей на странице (по умолчанию: 20)
- `search` (string) - Поиск по имени или логину
- `role` (string) - Фильтр по роли
- `status` (int) - Фильтр по статусу (1 - активен, 0 - заблокирован)
- `company_id` (int) - Фильтр по компании

#### GET /api/users/{id}
Получение информации о пользователе

#### POST /api/users
Создание пользователя (только для админов)

**Параметры:**
- `login` (string, required) - Логин
- `password` (string, required) - Пароль (минимум 6 символов)
- `first_name` (string, required) - Имя
- `last_name` (string, required) - Фамилия
- `middle_name` (string, optional) - Отчество
- `phone` (string, optional) - Телефон
- `role` (string, required) - Роль (admin, pc_operator, warehouse_worker, sales_manager)
- `company_id` (int, optional) - ID компании
- `warehouse_id` (int, optional) - ID склада

#### PUT /api/users/{id}
Обновление пользователя

#### DELETE /api/users/{id}
Блокировка пользователя (только для админов)

### Остатки

#### GET /api/inventory
Получение остатков товаров

**Параметры запроса:**
- `page` (int) - Номер страницы
- `limit` (int) - Количество записей на странице
- `warehouse_id` (int) - Фильтр по складу
- `template_id` (int) - Фильтр по шаблону товара
- `manufacturer` (string) - Фильтр по производителю
- `search` (string) - Поиск по названию товара

#### GET /api/inventory/warehouse/{id}
Получение остатков конкретного склада

#### GET /api/inventory/product/{id}
Получение остатков конкретного товара по всем складам

## Коды ошибок

- `200` - OK
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `405` - Method Not Allowed
- `422` - Unprocessable Entity (ошибки валидации)
- `500` - Internal Server Error

## Роли и права доступа

### admin (Администратор)
- Полный доступ ко всем endpoint'ам
- Может управлять пользователями и компаниями

### pc_operator (Оператор ПК)
- Доступ к товарам, остаткам, товарам в пути
- Только чтение

### warehouse_worker (Работник склада)
- Доступ к запросам, остаткам, товарам в пути, реализации, приемке
- Может создавать и обновлять записи

### sales_manager (Менеджер по продажам)
- Доступ к запросам, остаткам, товарам в пути
- Только чтение

## Примеры использования

### Получение остатков для склада

```javascript
const response = await fetch('/api/inventory?warehouse_id=1&limit=50', {
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    }
});

const data = await response.json();
console.log(data.data); // Массив остатков
```

### Создание пользователя

```javascript
const newUser = {
    login: 'newuser',
    password: 'password123',
    first_name: 'Иван',
    last_name: 'Иванов',
    role: 'warehouse_worker',
    company_id: 1,
    warehouse_id: 2
};

const response = await fetch('/api/users', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify(newUser)
});
```

## Статус разработки

### ✅ Готово
- Базовая структура API
- Аутентификация через токены
- Управление пользователями
- Просмотр остатков
- Система ролей

### 🚧 В разработке
- Управление компаниями и складами
- Работа с товарами и шаблонами
- Система запросов
- Продажи и транспортировка

### 📋 Планируется
- Загрузка файлов
- Ограничение скорости запросов
- Версионирование API
- WebSocket уведомления

## Поддержка

При возникновении проблем с API:

1. Проверьте правильность токена аутентификации
2. Убедитесь в корректности формата JSON
3. Проверьте права доступа для вашей роли
4. Обратитесь к логам сервера для детальной диагностики