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

# Create .env if it doesn't exist
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:Zx+s2Ny+lp7DyEoxkOEOxP1LQ1ddApApI9THzZ05e0I=" ]; then
    echo "Generating application key..."
    php artisan key:generate
fi

# Check if database is configured
if [ ! -z "$DATABASE_URL" ]; then
    echo "🔄 Running migrations..."
    php artisan migrate --force
    echo "✅ Migrations completed!"
else
    echo "⚠️ DATABASE_URL not configured"
fi

# Optimize
php artisan config:cache || echo "Config cache failed"
php artisan route:cache || echo "Route cache failed"
php artisan view:cache || echo "View cache failed"

# Start Apache
apache2-foreground