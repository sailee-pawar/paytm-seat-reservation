#!/bin/sh

set -e

echo "Running database migrations..."
php artisan migrate --force

echo "Starting Laravel application..."
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"