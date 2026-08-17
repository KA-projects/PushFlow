#!/bin/sh
set -e

# Устанавливаем зависимости при первом запуске (vendor монтируется с хоста и изначально пуст).
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

exec "$@"