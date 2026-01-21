# Инструкция по установке и настройке

## Шаги установки

### 1. Настройка базы данных

Создайте базу данных MySQL с именем `ecommerce_shop_db`:

```sql
CREATE DATABASE ecommerce_shop_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Настройка .env файла

Откройте файл `.env` и настройте подключение к базе данных:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ecommerce_shop_db
DB_USERNAME=ваш_пользователь
DB_PASSWORD=ваш_пароль
```

### 3. Установка зависимостей

```bash
composer install
```

### 4. Генерация ключа приложения

```bash
php artisan key:generate
```

### 5. Запуск миграций

```bash
php artisan migrate
```

### 6. Запуск сервера разработки

```bash
php artisan serve
```

API будет доступно по адресу: `http://localhost:8000/api`

## Структура проекта

### Модели
- `User` - Пользователи
- `Category` - Категории товаров
- `Product` - Товары
- `Cart` - Корзины
- `CartItem` - Элементы корзины
- `Address` - Адреса доставки
- `Order` - Заказы
- `OrderItem` - Элементы заказа
- `Payment` - Платежи
- `Review` - Отзывы

### Контроллеры API
- `AuthController` - Аутентификация
- `CategoryController` - Управление категориями
- `ProductController` - Управление товарами
- `CartController` - Управление корзиной
- `OrderController` - Управление заказами
- `AddressController` - Управление адресами
- `PaymentController` - Управление платежами
- `ReviewController` - Управление отзывами

### Миграции
Все миграции находятся в `database/migrations/`:
- `2024_01_01_000001_create_categories_table.php`
- `2024_01_01_000002_create_products_table.php`
- `2024_01_01_000003_create_addresses_table.php`
- `2024_01_01_000004_create_carts_table.php`
- `2024_01_01_000005_create_cart_items_table.php`
- `2024_01_01_000006_create_orders_table.php`
- `2024_01_01_000007_create_order_items_table.php`
- `2024_01_01_000008_create_payments_table.php`
- `2024_01_01_000009_create_reviews_table.php`

## Основные функции

✅ Полноценный CRUD для всех сущностей
✅ Аутентификация через Laravel Sanctum
✅ Управление корзиной покупок
✅ Создание и управление заказами
✅ Автоматическое управление складом
✅ Система платежей
✅ Отзывы на товары
✅ Управление адресами доставки
✅ Иерархия категорий
✅ Поиск и фильтрация товаров

## Тестирование API

Используйте Postman, Insomnia или любой другой HTTP клиент для тестирования API.

Пример запроса на регистрацию:
```bash
POST http://localhost:8000/api/register
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

Подробная документация API находится в файле `README_API.md`.

