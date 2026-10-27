#!/bin/sh
# Entrypoint bersama: app, queue, scheduler. Hanya kontainer ber-RUN_MIGRATIONS=true yang migrasi.
set -e
cd /var/www/html

php artisan package:discover --ansi >/dev/null 2>&1 || true
php artisan storage:link >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
    php artisan postivo:admin:create || echo "[entrypoint] ADMIN_EMAIL/ADMIN_PASSWORD belum diisi — lewati pembuatan admin."
fi

if [ "${APP_ENV}" != "local" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
exec "$@"
