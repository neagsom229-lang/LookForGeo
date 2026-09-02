FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip libpq-dev \
    supervisor \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Create directories and set permissions
RUN mkdir -p bootstrap/cache storage/framework/views storage/framework/cache storage/framework/sessions storage/logs \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Install dependencies (no npm needed for pure PHP apps, skip if not required)
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Enable Apache mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Configure Apache to serve from /public
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Allow .htaccess overrides (optional)
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf

# Copy Supervisor config
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose port 8080 (Render maps this automatically)
EXPOSE 8080

CMD ["/usr/local/bin/entrypoint.sh"]