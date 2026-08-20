FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libpq-dev \
    nodejs \
    npm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (PostgreSQL instead of SQLite)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd pgsql pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Install composer dependencies
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Install npm dependencies and build assets
RUN npm ci --legacy-peer-deps || npm i --legacy-peer-deps
RUN npm run build || echo "No build script found"

# Create storage and bootstrap cache directories
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions \
    && mkdir -p bootstrap/cache

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure Apache to serve from public directory
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

# Copy .env file
COPY .env.example .env
RUN php artisan key:generate

# Run migrations
RUN php artisan migrate --force

EXPOSE 8080
CMD ["apache2-foreground"]