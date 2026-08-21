#!/bin/bash

# Create storage directories
mkdir -p storage/framework/views
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Generate key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate
fi

# Check if database is configured (DATABASE_URL or DB_HOST)
if [ ! -z "$DATABASE_URL" ] || ([ ! -z "$DB_HOST" ] && [ "$DB_HOST" != "your-database-host.render.com" ]); then
    echo "Running migrations..."
    
    # Wait for database to be ready
    echo "Waiting for database connection..."
    for i in {1..10}; do
        if php artisan migrate --force; then
            break
        fi
        echo "Database not ready, retrying... ($i/10)"
        sleep 3
    done
else
    echo "⚠️ Database not configured. DATABASE_URL: $DATABASE_URL"
fi

# Optimize
php artisan config:cache || echo "Config cache failed"
php artisan route:cache || echo "Route cache failed"
php artisan view:cache || echo "View cache failed"

# Start Apache
apache2-foreground