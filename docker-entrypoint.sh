#!/bin/sh
set -e

# Устанавливаем зависимости при первом запуске (vendor монтируется с хоста и изначально пуст).
# flock-файл лежит внутри смонтированного vendor, чтобы app и queue (разные контейнеры)
# не выполняли composer install / key:generate одновременно в один каталог.
(
    flock -w 600 9
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi
    if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
        php artisan key:generate --force --no-interaction
    fi
) 9>vendor/.composer-install.lock

exec "$@"