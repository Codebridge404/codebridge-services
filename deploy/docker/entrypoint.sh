#!/bin/sh
set -eu
: "${APP_KEY:?Set APP_KEY in the per-service Kubernetes Secret}"
: "${JWT_SECRET:?Set the shared JWT_SECRET in both service Secrets}"
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
  storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan package:discover --ansi
php artisan config:cache
exec "$@"
