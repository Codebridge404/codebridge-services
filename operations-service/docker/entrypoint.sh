#!/bin/sh
set -e

mkdir -p /var/www/html/storage/app/public/products \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

if [ "${APP_ENV:-local}" = "production" ] || [ "${APP_ENV:-local}" = "staging" ]; then
    php /var/www/html/artisan optimize --no-interaction >/dev/null 2>&1 || true
fi

exec "$@"
