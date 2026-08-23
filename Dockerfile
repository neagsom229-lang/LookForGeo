# Install dependencies
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Install npm dependencies
RUN npm install --legacy-peer-deps

# Build assets
RUN npm run build || echo "No build script found"

# Create storage directories
RUN mkdir -p storage/framework/views \
    && mkdir -p storage/framework/cache \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/logs

# Create the public storage symlink
RUN php artisan storage:link

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache