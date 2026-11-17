# Stage 1: Build Frontend Assets (Node + Vite)
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
RUN npm run build

# Stage 2: Production PHP 8.3 FPM + Nginx + Composer
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    nginx \
    sqlite \
    sqlite-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    curl \
    zip \
    unzip \
    bash \
    && docker-php-ext-install \
    bcmath \
    pdo_sqlite \
    mbstring \
    xml \
    zip \
    intl \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .
COPY --from=frontend /app/public/build ./public/build

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache database \
    && chown -R www-data:www-data storage bootstrap/cache database

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]