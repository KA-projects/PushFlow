FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libonig-dev \
        libssl-dev \
        libcurl4-openssl-dev \
        pkg-config \
        unzip \
        git \
        curl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        mbstring \
        pcntl \
        bcmath \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY . .

RUN (composer install --no-interaction --prefer-dist --optimize-autoloader --no-audit \
        || composer install --no-interaction --prefer-dist --optimize-autoloader --no-audit \
        || echo "WARNING: build-time composer install failed (network); deps will be installed at container start") \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENTRYPOINT ["docker-entrypoint.sh"]
