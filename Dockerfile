# ==============================================================================
# CodeBridges Enterprise - Unified Cloud Container (Render Production)
# Packages: Nginx Gateway + 6 Laravel Microservices + Supervisor
# ==============================================================================
FROM php:8.2-cli-alpine

# 1. Install System Tools, Nginx, Supervisor, PHP Extensions
RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    curl \
    ca-certificates \
    git \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    mysql-client \
    netcat-openbsd \
    linux-headers \
    && docker-php-ext-install pdo_mysql bcmath zip sockets \
    && mkdir -p /run/nginx /var/log/nginx /var/log/supervisor /var/run

# 2. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Create app directories
WORKDIR /var/www

# 4. Copy both consolidated Laravel microservices
COPY ./auth-service /var/www/auth-service
COPY ./operations-service /var/www/operations-service

# 5. Install Composer dependencies for each service
RUN for dir in auth-service operations-service; do \
      if [ -f "/var/www/$dir/composer.json" ]; then \
        echo "Installing dependencies for $dir..." && \
        cd /var/www/$dir && \
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction && \
        mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && \
        chmod -R 777 storage bootstrap/cache || true; \
      fi \
    done

# 6. Configure Nginx and Supervisor
COPY ./docker/cloud-nginx.conf /etc/nginx/nginx.conf
COPY ./gateway/docs.html /usr/share/nginx/html/docs.html
COPY ./docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./docker/start-unified.sh /usr/local/bin/start-unified.sh
RUN chmod +x /usr/local/bin/start-unified.sh

# 7. Expose Gateway Port
EXPOSE 80

# 8. Start All Services & Nginx Gateway via Supervisor
CMD ["/usr/local/bin/start-unified.sh"]
