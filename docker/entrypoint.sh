#!/bin/sh
set -e

APP_DIR=/var/www/html/lintune-admin

if [ ! -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

patch_env() {
    local key="$1" val="$2" tmp
    [ -z "$val" ] && return
    # sed -i renames a temp file over the original, which fails on bind-mounted files
    # ("Resource busy"). Instead: sed to a temp file, cat it back into the original
    # inode so the mount point stays intact, then remove the temp.
    tmp=$(mktemp)
    sed "s|^${key}=.*|${key}=${val}|" "$APP_DIR/.env" > "$tmp"
    cat "$tmp" > "$APP_DIR/.env"
    rm -f "$tmp"
}

patch_env APP_KEY     "$APP_KEY"
patch_env APP_URL     "$APP_URL"
patch_env DB_HOST     "$DB_HOST"
patch_env DB_DATABASE "$DB_DATABASE"
patch_env DB_USERNAME "$DB_USERNAME"
patch_env DB_PASSWORD "$DB_PASSWORD"

chown www-data:www-data "$APP_DIR/.env"
chmod 640 "$APP_DIR/.env"

echo "Waiting for database..."
until mariadb -h"${DB_HOST:-db}" -u"${DB_USERNAME:-lintune}" -p"${DB_PASSWORD:-secret}" \
      -e "SELECT 1" >/dev/null 2>&1; do
    printf '.'
    sleep 2
done
echo ""
echo "Database ready."

php artisan migrate --force --no-interaction

php artisan config:cache
php artisan route:cache
php artisan view:cache

php-fpm -D

exec nginx -g "daemon off;"
