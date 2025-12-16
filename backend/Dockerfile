FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libonig-dev curl \
    && docker-php-ext-install pdo pdo_mysql zip mbstring pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Prevent artisan auto-run during composer install
RUN composer clear-cache \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache \
    && find storage -type d -exec chmod 775 {} \; \
    && find storage -type f -exec chmod 664 {} \; \
    && chmod -R 775 bootstrap/cache


COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
