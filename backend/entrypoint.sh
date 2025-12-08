#!/bin/sh
set -e
cd /var/www/html

if [ ! -f ".env" ]; then
    cp .env.example .env
fi

composer install --no-dev --optimize-autoloader

php artisan key:generate --force
php artisan migrate --force

RUN chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

exec php-fpm
