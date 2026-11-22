#!/bin/sh
set -e

echo "⏳ Waiting for database connection (max 10 attempts)..."
attempt=0
while [ "$attempt" -lt 10 ]; do
  attempt=$((attempt + 1))
  # Print the real migration error to the Render log on every attempt.
  if output=$(php artisan migrate --force 2>&1); then
    printf '%s\n' "$output"
    break
  fi
  printf '%s\n' "$output"
  if [ "$attempt" -ge 10 ]; then
    echo "ERROR: migrations still failing after 10 attempts; exiting non-zero so Render marks the deploy failed."
    exit 1
  fi
  echo "Migration attempt $attempt/10 failed; retrying in 5 seconds..."
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

echo "⚡ Caching configuration, routes and views for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "🚀 Starting Supervisor (Apache web server)..."
# NOTE: this container only serves HTTP. The database queue worker is a
# SEPARATE Render service (see render.yaml -> queue-worker), so the comment
# historically claiming "Web + Queue Worker" here was misleading.
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf