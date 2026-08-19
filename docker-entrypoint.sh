#!/bin/sh
set -e

# Устанавливаем зависимости при первом запуске (vendor монтируется с хоста и изначально пуст).
# flock-файл лежит внутри смонтированного vendor, чтобы app и queue (разные контейнеры)
# не запускали composer install одновременно в один каталог.
if [ ! -f vendor/autoload.php ]; then
    (
        flock -w 600 9
        if [ ! -f vendor/autoload.php ]; then
            composer install --no-interaction --prefer-dist --optimize-autoloader
        fi
    ) 9>vendor/.composer-install.lock
fi

exec "$@"