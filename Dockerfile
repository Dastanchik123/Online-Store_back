FROM php:8.2-apache

# Установка системных зависимостей
# Установка необходимых расширений PHP через надежный скрипт
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions pdo_mysql gd bcmath zip intl opcache mbstring xml curl dom fileinfo tokenizer ctype iconv

# Включение модуля Apache Rewrite
RUN a2enmod rewrite

# Настройка корневой директории Apache для Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копирование проекта
COPY . /var/www/html

# Установка зависимостей Laravel
RUN composer install --no-dev --optimize-autoloader --no-scripts -vvv

# Настройка прав доступа
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Порт по умолчанию для Render
EXPOSE 80

CMD ["apache2-foreground"]
