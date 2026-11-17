#!/usr/bin/env sh
set -eu

cd /var/www/html

# Ensure required Laravel writable directories exist
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Prepare SQLite database file if sqlite connection is used
DB_CONN="${DB_CONNECTION:-sqlite}"
if [ "$DB_CONN" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    mkdir -p "$(dirname "$DB_PATH")"
    if [ ! -f "$DB_PATH" ]; then
        touch "$DB_PATH"
    fi
    chown -R www-data:www-data "$(dirname "$DB_PATH")"
    chmod 664 "$DB_PATH"
fi

chown -R www-data:www-data storage bootstrap/cache

# Generate fallback APP_KEY only when not supplied via environment
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f .env ]; then
        cp .env.example .env
    fi
    php artisan key:generate --force --no-interaction
fi

# Run migrations and warm Laravel caches
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Ensure www-data owns all generated caches, logs, and sqlite files
chown -R www-data:www-data storage bootstrap/cache
if [ "$DB_CONN" = "sqlite" ]; then
    chown -R www-data:www-data "$(dirname "$DB_PATH")"
fi

# Bind Nginx to cloud-provided PORT (Render/Railway/Fly) or default 8080
LISTEN_PORT="${PORT:-8080}"
sed "s/__PORT__/${LISTEN_PORT}/g" /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

php-fpm -D
exec nginx -g "daemon off;"
