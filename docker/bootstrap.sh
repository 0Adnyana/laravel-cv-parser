#!/bin/sh

database_configured() {
    if [ -n "$DB_URL" ]; then
        return 0
    fi

    if [ -n "$DB_HOST" ]; then
        return 0
    fi

    if [ -n "$DB_CONNECTION" ] && [ "$DB_CONNECTION" != "sqlite" ]; then
        return 0
    fi

    if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ]; then
        return 0
    fi

    return 1
}

bootstrap_app_key() {
    if [ -n "$APP_KEY" ]; then
        return 0
    fi

    echo "FATAL: APP_KEY is not set." >&2
    echo "Generate one, then pass it to the container:" >&2
    echo "  docker run --rm <image> php artisan key:generate --show" >&2
    echo "  # then set it, e.g.  -e APP_KEY=base64:xxxxxxxx..." >&2
    exit 1
}

bootstrap_migrations() {
    if [ "${RUN_MIGRATIONS:-true}" != "true" ]; then
        echo "RUN_MIGRATIONS is not 'true', skipping migrations."
        return 0
    fi

    if ! database_configured; then
        echo "No database configured, skipping migrations."
        return 0
    fi

    echo "Running database migrations..."
    php artisan migrate --force --isolated
}
