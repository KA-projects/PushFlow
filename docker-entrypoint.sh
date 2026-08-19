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

# Генерируем APP_KEY, если он не задан ни в окружении, ни в .env.
if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
    php artisan key:generate --force --no-interaction
fi

exec "$@"