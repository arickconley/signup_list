#!/bin/sh
set -eu

mkdir -p \
    /srv/signup/backups \
    /srv/signup/database \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

touch /srv/signup/database/database.sqlite
chown -R www-data:www-data /srv/signup bootstrap/cache storage

php artisan optimize
php artisan app:production-check
php artisan migrate --force --no-interaction

chown -R www-data:www-data /srv/signup bootstrap/cache storage

exec "$@"
