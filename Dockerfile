FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libonig-dev libxml2-dev libzip-dev zip unzip libpq-dev nginx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --optimize-autoloader --no-dev

RUN php artisan storage:link

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY nginx.conf /etc/nginx/sites-available/default

EXPOSE 8080

# queue:work в фоне того же контейнера/диска — тяжёлые PDF-отчёты (см.
# App\Jobs\GenerateReportExport) генерируются здесь же, файл сразу доступен
# и для web-процесса (download endpoint). Отдельная Fly-машина под воркер не
# нужна: report-запросы редкие, а два процесса на разных дисках не видели бы
# один и тот же сгенерированный файл (Fly volume attach'ится к одной машине).
CMD chown -R www-data:www-data /var/www/html/storage && \
    (while true; do php artisan queue:work --tries=2 --timeout=180 --sleep=3; sleep 2; done &) && \
    php-fpm -D && nginx -g "daemon off;"