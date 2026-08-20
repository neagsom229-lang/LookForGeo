#!/bin/bash

# Create storage directories if they don't exist
mkdir -p storage/framework/views
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate
fi

# Run migrations (only if database is available)
if [ ! -z "$DB_HOST" ] && [ "$DB_HOST" != "your-database-host.render.com" ]; then
    echo "Running migrations..."
    php artisan migrate --force
else
    echo "Skipping migrations - database not configured"
fi

# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start Apache
apache2-foreground