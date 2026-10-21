# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# PHP-FPM runtime with the extensions the app needs.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
# pcntl: queue workers need it to enforce job timeouts. redis: phpredis client.
RUN install-php-extensions pdo_mysql intl zip opcache pcntl redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Composer dependencies. INSTALL_DEV=true (compose default) keeps PHPUnit,
# Larastan and Pint in the image so the checks can run in the container.
# ---------------------------------------------------------------------------
FROM base AS vendor

ARG INSTALL_DEV=false
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader \
    $( [ "$INSTALL_DEV" = "true" ] || echo "--no-dev" )

# ---------------------------------------------------------------------------
# Front-end assets (Vite). Tailwind scans Blade views in vendor/, so the
# Composer stage is copied in first.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /var/www/html/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# Application image: used by the app (FPM), queue and scheduler services.
# ---------------------------------------------------------------------------
FROM base AS app

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Configuration comes from environment variables (compose.yaml); the empty
# .env only stops the dotenv loader from warning about a missing file.
RUN composer dump-autoload --optimize --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && touch .env \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache .phpunit.cache \
    && chown -R www-data:www-data storage bootstrap/cache .phpunit.cache

USER www-data

# ---------------------------------------------------------------------------
# Nginx with the public directory baked in; PHP requests go to the app service.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
