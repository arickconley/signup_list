FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && php artisan package:discover --ansi

FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources resources
COPY vite.config.js ./
COPY --from=composer /app/vendor vendor
RUN npm run build

FROM php:8.4-apache-bookworm AS runtime

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libicu-dev \
        libsqlite3-dev \
        libzip-dev \
        supervisor \
        unzip \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_sqlite zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer /app ./
COPY --from=assets /app/public/build public/build
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/signup.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/signup.conf
COPY docker/entrypoint.sh /usr/local/bin/signup-entrypoint

RUN chmod +x /usr/local/bin/signup-entrypoint \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

EXPOSE 80
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=10s --timeout=5s --start-period=45s --retries=6 \
    CMD curl --fail --silent --show-error http://127.0.0.1/up > /dev/null || exit 1

ENTRYPOINT ["signup-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/signup.conf"]
