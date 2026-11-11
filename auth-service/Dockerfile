FROM php:8.4.27-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    libzip-dev \
    libonig-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
    pdo_mysql \
    mbstring \
    zip \
    pcntl \
    bcmath \
    gd \
    opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl clear-cache \
    && rm -rf /tmp/pear /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Custom PHP & FPM configurations
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/security.ini /usr/local/etc/php/conf.d/security.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod 0755 /usr/local/bin/entrypoint.sh

ARG INSTALL_DEV=false

COPY composer.json composer.lock ./

RUN if [ "$INSTALL_DEV" = "true" ]; then \
        composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader; \
    else \
        composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader; \
    fi

COPY . .

RUN if [ "$INSTALL_DEV" = "true" ]; then \
        composer dump-autoload --optimize; \
    else \
        composer dump-autoload --no-dev --optimize \
        && rm -f /usr/bin/composer /var/www/html/.env /var/www/html/.env.* \
        && rm -rf /root/.composer /var/www/html/tests \
        && apt-get purge -y --auto-remove git unzip \
        && rm -rf /var/lib/apt/lists/*; \
    fi \
    && mkdir -p \
        storage/app/public/avatars \
        storage/app/private/backups \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R 0:33 /var/www/html \
    && chmod -R go-w /var/www/html \
    && chown -R 33:33 storage bootstrap/cache \
    && chmod -R u+rwX,g+rwX,o= storage bootstrap/cache

USER 33:33

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD php -r '$cmd = @file_get_contents("/proc/1/cmdline") ?: ""; if (str_contains($cmd, "php-fpm")) { $s = @fsockopen("127.0.0.1", 9000, $errno, $errstr, 2); if (!$s) { exit(1); } fclose($s); exit(0); } exit(is_readable("/var/www/html/artisan") ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm", "-F"]
