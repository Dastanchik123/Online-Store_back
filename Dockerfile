# Используем официальный образ PHP-FPM
FROM php:8.3-fpm
# Установка системных зависимостей и Nginx
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev libzip-dev zip unzip libpq-dev nginx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Установка расширений PHP (включая pdo_pgsql для Neon)
RUN docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настройка рабочей директории
WORKDIR /var/www/html

# Копируем файлы проекта
COPY . .

# Устанавливаем зависимости Laravel
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Права доступа
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Настройка Nginx
RUN echo 'server { \
    listen 8080; \
    root /var/www/html/public; \
    index index.php index.html; \
    location / { \
        try_files $uri $uri/ /index.php?$query_string; \
    } \
    location ~ \.php$ { \
        include fastcgi_params; \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; \
    } \
}' > /etc/nginx/sites-available/default

# Указываем порт для Fly
EXPOSE 8080

# Скрипт запуска: стартуем PHP-FPM и Nginx
CMD php-fpm -D && nginx -g "daemon off;"
