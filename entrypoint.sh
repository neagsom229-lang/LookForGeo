#!/bin/sh
set -e

echo "Running database migrations..."
php artisan migrate --force

echo "Creating storage link (for local file storage)..."
php artisan storage:link

echo "Starting Supervisor (Web + Queue Worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
