#!/bin/sh
set -e

echo "⏳ Waiting for database connection..."
until php artisan migrate --force; do
  echo "Migration failed, retrying in 5 seconds..."
  sleep 5
done

echo "✅ Migrations completed!"

echo "🔗 Creating storage link..."
php artisan storage:link || echo "Storage link already exists or failed, continuing..."

echo "🛠️ Setting permissions for storage and public..."
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache public

echo "🚀 Starting Supervisor (Web + Queue Worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf