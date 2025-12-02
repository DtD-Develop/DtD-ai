#!/bin/sh

set -e

cd /var/www/html

echo ">> Running composer install (if needed)"
composer install --no-dev --optimize-autoloader

echo ">> Running Laravel setup"
php artisan key:generate --force || true
php artisan migrate --force || true

echo ">> Fixing permissions"
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R 775 storage bootstrap/cache || true

# Copy .env if not exist
if [ ! -f ".env" ]; then
    cp .env.example .env
fi

php artisan key:generate --force || true
php artisan migrate --force || true
php artisan config:clear || true

echo ">> Starting PHP-FPM"
exec php-fpm
