#!/bin/bash
set -e

# Create required directories
mkdir -p storage/framework/views
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Set proper permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate
fi

# Run migrations (only if DATABASE_URL is set)
if [ ! -z "$DATABASE_URL" ] || [ ! -z "$DB_HOST" ]; then
    php artisan migrate --force
fi

# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Execute the main command
exec "$@"