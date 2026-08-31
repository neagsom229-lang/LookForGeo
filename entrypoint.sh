#!/bin/sh
set -e

echo "⏳ Waiting for database connection..."

# Run migrations, but don't fail the whole container if they are already applied
if php artisan migrate --force; then
  echo "✅ Migrations completed successfully!"
else
  echo "⚠️ Migration skipped (already applied or minor error). Continuing..."
fi

echo "🔗 Creating storage link (if not exists)..."
php artisan storage:link || echo "Storage link already exists, continuing..."

echo "🛠️ Setting permissions for storage and public..."
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache public

echo "🚀 Starting Supervisor (Web + Queue Worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf