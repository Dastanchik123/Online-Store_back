FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libonig-dev libxml2-dev libzip-dev zip unzip libpq-dev nginx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --optimize-autoloader --no-dev --no-scripts

RUN php artisan storage:link

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY nginx.conf /etc/nginx/sites-available/default

EXPOSE 8080

CMD php-fpm -D && nginx -g "daemon off;"