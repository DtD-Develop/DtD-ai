FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libonig-dev curl \
    && docker-php-ext-install pdo pdo_mysql zip mbstring pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

CMD ["php-fpm"]
