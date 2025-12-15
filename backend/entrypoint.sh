#!/bin/sh
set -e
cd /var/www/html

# ❌ ห้ามสร้างหรือแก้ .env ใน container
if [ ! -f ".env" ]; then
  echo ".env not found, exiting"
  exit 1
fi

composer install --no-dev --optimize-autoloader

php artisan migrate --force || true

chown -R www-data:www-data storage bootstrap/cache

exec php-fpm
