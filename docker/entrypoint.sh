#!/bin/sh
set -eu

for dir in /var/www/html/var/data /var/www/html/var/log /var/www/html/var/sessions; do
    mkdir -p "$dir"
    chown www-data:www-data "$dir"
    chmod 0770 "$dir"
done

exec docker-php-entrypoint "$@"
