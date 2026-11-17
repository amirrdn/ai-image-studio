# syntax=docker/dockerfile:1

# Stage 1: Install PHP production dependencies
FROM composer:2 AS vendor
WORKDIR /app

ENV COMPOSER_NO_SECURITY_BLOCKING=1

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# Stage 2: Build Vite + Vue 3 + Tailwind CSS assets
FROM node:20-alpine AS assets
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/ ./resources/
RUN npm run build

# Stage 3: Production PHP-FPM + Nginx runtime
FROM php:8.3-fpm-alpine AS production

RUN apk add --no-cache nginx sqlite sqlite-libs

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath pdo_sqlite mbstring xml intl zip opcache

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p /etc/nginx/templates /run/nginx \
    && cp docker/nginx.conf /etc/nginx/templates/default.conf.template \
    && chmod +x /var/www/html/docker/entrypoint.sh \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]

