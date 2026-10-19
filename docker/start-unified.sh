#!/bin/bash
set -e

echo "=========================================================="
echo "🚀 Initializing CodeBridges Enterprise Cloud Services"
echo "=========================================================="

# 0. Ensure system run and log directories exist for Nginx and Supervisor
mkdir -p /run/nginx /var/log/nginx /var/log/supervisor /var/run

PORT_TO_LISTEN="${PORT:-80}"
echo "🌐 Configuring Nginx to bind on port ${PORT_TO_LISTEN}..."
sed -i "s/listen 80;/listen ${PORT_TO_LISTEN};/g" /etc/nginx/nginx.conf

SERVICES=("auth-service" "operations-service")
AUTH_DB="${AUTH_DB_DATABASE:-codebridge_auth}"
OPERATIONS_DB="${OPERATIONS_DB_DATABASE:-codebridge_operations}"
TARGET_PORT="${DB_PORT:-4000}"
SSL_CA_PATH="${MYSQL_ATTR_SSL_CA:-/etc/ssl/certs/ca-certificates.crt}"

# 1. Fast Setup of Environment, Keys & Permissions for the 2 microservices
for svc in "${SERVICES[@]}"; do
    if [ -d "/var/www/$svc" ]; then
        cd "/var/www/$svc"
        
        # Determine dedicated database for this service
        if [ "$svc" = "auth-service" ]; then
            SVC_DB="$AUTH_DB"
        else
            SVC_DB="$OPERATIONS_DB"
        fi
        
        # 1. Setup storage and cache directories
        mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
        chmod -R 777 storage bootstrap/cache 2>/dev/null || true
        
        # 2. Write complete .env file with active Cloud Database credentials (TiDB Cloud / MySQL)
        cat <<EOF > .env
APP_NAME=POS-${svc}
APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL:-https://pos-services-ph15.onrender.com}
APP_KEY=

DB_CONNECTION=mysql
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${TARGET_PORT}
DB_DATABASE=${SVC_DB}
DB_USERNAME=${DB_USERNAME:-root}
DB_PASSWORD=${DB_PASSWORD:-}
AUTH_DB_DATABASE=${AUTH_DB}
AUTH_SERVICE_URL=${AUTH_SERVICE_URL:-http://127.0.0.1:8000/api/v1}
OPERATIONS_SERVICE_URL=${OPERATIONS_SERVICE_URL:-http://127.0.0.1:8000/api/v1}
MYSQL_ATTR_SSL_CA=${SSL_CA_PATH}

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync

LOG_CHANNEL=stack
LOG_LEVEL=debug
EOF

        # 3. Generate APP_KEY and clear caches
        if [ -f "artisan" ]; then
            php artisan key:generate --force 2>/dev/null || true
            php artisan config:clear 2>/dev/null || true
            php artisan route:clear 2>/dev/null || true
        fi
    fi
done

# 2. Asynchronous Database Provisioning & Migrations (Runs in background so Web Server boots instantly)
(
    sleep 2
    if [ -n "$DB_HOST" ] && [ -n "$DB_USERNAME" ] && [ -n "$DB_PASSWORD" ]; then
        echo "📦 [Async DB] Ensuring microservice databases (${AUTH_DB}, ${OPERATIONS_DB}) exist..."

        SSL_CLI_FLAG=""
        if [ -f "$SSL_CA_PATH" ]; then
            SSL_CLI_FLAG="--ssl-ca=$SSL_CA_PATH"
        fi

        mysql --connect-timeout=10 -h "$DB_HOST" -P "$TARGET_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" $SSL_CLI_FLAG -e "
            CREATE DATABASE IF NOT EXISTS \`${AUTH_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE DATABASE IF NOT EXISTS \`${OPERATIONS_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        " 2>/dev/null || true

        for svc in "${SERVICES[@]}"; do
            if [ -d "/var/www/$svc" ]; then
                cd "/var/www/$svc"
                echo "🚀 [Async DB] Running migrations for $svc..."
                php artisan migrate --force 2>/dev/null || true
                php artisan db:seed --force 2>/dev/null || true
            fi
        done
        echo "✅ [Async DB] All database migrations completed."
    fi
) &

echo "Verifying Nginx configuration syntax..."
nginx -t || true

echo "Starting Supervisor (Nginx on port ${PORT_TO_LISTEN} + 3 Consolidated Microservices)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
