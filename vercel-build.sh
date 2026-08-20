#!/bin/bash
set -e

# Install dependencies
composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Build frontend assets
npm ci --legacy-peer-deps || npm install --legacy-peer-deps
npm run build || echo "No build script found"

# Create storage directory
mkdir -p storage/framework/views
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions

# Clear and cache config
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# Cache for production
php artisan config:cache
php artisan route:cache
php artisan view:cache