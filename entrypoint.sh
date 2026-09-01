#!/bin/sh
set -e

echo "⏳ Waiting for database connection..."
until php artisan migrate --force; do
  echo "Migration failed, retrying in 5 seconds..."
  sleep 5
done
echo "✅ Migrations completed."

echo "🔗 Creating storage link (if not exists)..."
# Ensure the target directory exists
mkdir -p storage/app/public

# Attempt to create the symlink; if it fails, retry once
if ! php artisan storage:link; then
  echo "⚠️ Storage link creation failed. Retrying after 2 seconds..."
  sleep 2
  php artisan storage:link || {
    echo "❌ Storage link could not be created. Exiting."
    exit 1
  }
fi

# Verify the symlink actually points to the right place
if [ -L public/storage ] && [ -d storage/app/public ]; then
  echo "✅ Storage link verified (public/storage -> storage/app/public)"
else
  echo "❌ Storage link verification failed. Exiting."
  exit 1
fi

echo "🛠️ Setting permissions for storage and public..."
chown -R www-data:www-data storage bootstrap/cache public
chmod -R 775 storage bootstrap/cache

echo "⚡ Caching configuration and routes for production..."
php artisan config:cache
php artisan route:cache

# Clear old views, but keep them cached for production
php artisan view:clear

echo "🚀 Starting Supervisor (Web + Queue Worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf