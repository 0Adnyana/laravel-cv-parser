#!/bin/sh
set -e

if [ "$1" != "octane" ]; then
    exec "$@"
fi

. /app/docker/bootstrap.sh
bootstrap_app_key
bootstrap_migrations

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec php artisan octane:frankenphp --host=0.0.0.0 --port=8000
