FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libonig-dev curl \
    && docker-php-ext-install pdo pdo_mysql zip mbstring pcntl

RUN chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
