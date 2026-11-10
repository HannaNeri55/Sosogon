#!/bin/sh
set -e

# Render mounts secret files (like the Aiven CA certificate) readable by root only, but
# Apache's PHP runs as www-data. Copy the certificate somewhere www-data can read and point
# Laravel at the copy before the config is cached.
if [ -n "$MYSQL_ATTR_SSL_CA" ] && [ -f "$MYSQL_ATTR_SSL_CA" ]; then
    cp "$MYSQL_ATTR_SSL_CA" /usr/local/share/db-ca.pem
    chmod 644 /usr/local/share/db-ca.pem
    export MYSQL_ATTR_SSL_CA=/usr/local/share/db-ca.pem
fi

# Config is cached here (not at build) because Render injects env vars at runtime.
# Only `migrate --force` runs here: never migrate:fresh or db:seed against production.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
php artisan migrate --force

# The commands above ran as root; hand the writable folders back to Apache's user.
chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground
