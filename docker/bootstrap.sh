#!/bin/sh

APP_KEY_FILE="${APP_KEY_FILE:-/app/storage/app/.app_key}"
MIGRATIONS_MARKER="${MIGRATIONS_MARKER:-/app/storage/app/.migrations_applied}"

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

    if [ -f "$APP_KEY_FILE" ]; then
        APP_KEY=$(cat "$APP_KEY_FILE")
        export APP_KEY

        return 0
    fi

    APP_KEY=$(php artisan key:generate --show --no-interaction)
    export APP_KEY

    mkdir -p "$(dirname "$APP_KEY_FILE")"
    printf '%s' "$APP_KEY" > "$APP_KEY_FILE"

    echo "Auto-generated APP_KEY and persisted to $APP_KEY_FILE"
}

bootstrap_migrations() {
    if [ -f "$MIGRATIONS_MARKER" ]; then
        return 0
    fi

    if ! database_configured; then
        return 0
    fi

    php artisan migrate --force

    mkdir -p "$(dirname "$MIGRATIONS_MARKER")"
    touch "$MIGRATIONS_MARKER"
}
