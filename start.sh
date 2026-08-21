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

# Check if database is configured
if [ ! -z "$DATABASE_URL" ]; then
    echo "🔄 Checking database connection..."
    
    # Wait for database to be ready (retry up to 10 times)
    for i in {1..10}; do
        if php artisan migrate --force; then
            echo "✅ Migrations completed successfully!"
            break
        fi
        echo "⏳ Database not ready, retrying... ($i/10)"
        sleep 3
    done
else
    echo "⚠️ DATABASE_URL not configured"
fi

# Optimize
php artisan config:cache || echo "Config cache failed"
php artisan route:cache || echo "Route cache failed"
php artisan view:cache || echo "View cache failed"

# Start Apache
apache2-foreground