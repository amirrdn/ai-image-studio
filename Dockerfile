# Stage 1: Install Composer Dependencies & Build Frontend Assets
FROM php:8.3-cli-alpine AS builder

RUN apk add --no-cache nodejs npm unzip curl git

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

ENV COMPOSER_NO_SECURITY_BLOCKING=1

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts \
    --ignore-platform-reqs

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
RUN npm run build

# Stage 2: Production PHP 8.3 FPM + Nginx
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
    bash \
    && docker-php-ext-install -j$(nproc) \
    bcmath \
    pdo_sqlite \
    zip \
    intl \
    opcache

WORKDIR /var/www/html

COPY . .
COPY --from=builder /app/vendor ./vendor
COPY --from=builder /app/public/build ./public/build

RUN mkdir -p /etc/nginx/templates /run/nginx \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache/data \
    storage/logs \
    bootstrap/cache \
    database \
    && cp docker/nginx.conf /etc/nginx/templates/default.conf.template \
    && cp docker/entrypoint.sh /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && php -m | grep -E "bcmath|pdo_sqlite|mbstring|xml|intl|zip" \
    && chown -R www-data:www-data storage bootstrap/cache database

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
