#!/bin/bash

# Create storage directories
mkdir -p storage/framework/views
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/logs
mkdir -p bootstrap/cache

# ✅ Create the storage symlink
php artisan storage:link || echo "Symlink already exists or failed"

# Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Create .env if it doesn't exist
if [ ! -f .env ]; then
    cp .env.example .env
    echo "✅ Created .env from .env.example"
fi

# Generate key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" ]; then
    echo "🔑 Generating application key..."
    php artisan key:generate
    echo "✅ Application key generated!"
fi

# Check if database is configured
if [ ! -z "$DATABASE_URL" ]; then
    echo "🔄 Running migrations..."
    for i in {1..5}; do
        if php artisan migrate --force; then
            echo "✅ Migrations completed successfully!"
            break
        fi
        echo "⏳ Database not ready, retrying... ($i/5)"
        sleep 3
    done
else
    echo "⚠️ DATABASE_URL not configured"
fi

# Optimize
echo "⚙️ Optimizing..."
php artisan config:cache || echo "Config cache failed"
php artisan route:cache || echo "Route cache failed"
php artisan view:cache || echo "View cache failed"

# Start Apache
echo "🚀 Starting Apache..."
apache2-foreground