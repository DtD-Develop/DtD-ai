#!/bin/sh
set -e
cd /var/www/html

if [ ! -f ".env" ]; then
  echo ".env not found"
  exit 1
fi

php artisan key:generate --force || true
php artisan migrate --force || true
php artisan package:discover || true

exec php-fpm
