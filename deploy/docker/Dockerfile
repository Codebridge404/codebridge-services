# Build from the repository root. SERVICE_DIR must be one Laravel service.
ARG PHP_IMAGE=php:8.4-fpm-bookworm
ARG NGINX_IMAGE=nginxinc/nginx-unprivileged:stable-alpine
FROM ${PHP_IMAGE} AS app
ARG SERVICE_DIR=auth-service
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip libzip-dev libonig-dev libpng-dev libjpeg62-turbo-dev \
    libwebp-dev libfreetype6-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip pcntl bcmath gd intl opcache \
 && pecl install redis && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY ${SERVICE_DIR}/composer.json ${SERVICE_DIR}/composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
COPY ${SERVICE_DIR}/ ./
RUN rm -f bootstrap/cache/*.php \
 && mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --no-scripts \
 && composer check-platform-reqs --no-dev \
 && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY deploy/docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 deploy/docker/entrypoint.sh /usr/local/bin/app-entrypoint
USER 33:33
EXPOSE 9000
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM ${NGINX_IMAGE} AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY deploy/docker/default.conf /etc/nginx/conf.d/default.conf
RUN nginx -t
EXPOSE 8080
