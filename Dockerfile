# syntax=docker/dockerfile:1
# Production image: FrankenPHP (Caddy + PHP in a single process) in worker mode.
# The same image runs the API, the Messenger worker and the migrations.

FROM docker.io/dunglas/frankenphp:1-php8.4-alpine AS base

RUN install-php-extensions pdo_mysql intl opcache apcu gmp \
 && rm -rf /tmp/* /var/cache/apk/*

COPY --from=docker.io/composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    # HTTP only: TLS is left to the reverse proxy in front of the stack
    SERVER_NAME=":80" \
    # Kernel booted once, then reused for every request
    FRANKENPHP_CONFIG="worker ./public/index.php"

COPY <<'INI' $PHP_INI_DIR/conf.d/zz-app.ini
expose_php = 0
memory_limit = 256M
realpath_cache_size = 4096K
realpath_cache_ttl = 600
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
INI

# Dependencies first so this layer is cached as long as composer.lock does not change
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction \
 && composer clear-cache

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
 && composer dump-env prod \
 && composer run-script --no-dev post-install-cmd \
 && rm /usr/bin/composer \
 && chmod +x bin/console \
 && chown -R www-data:www-data var \
 # Preload only once the prod container (and its preload list) has been warmed up
 && printf 'opcache.preload = /app/config/preload.php\nopcache.preload_user = www-data\n' > $PHP_INI_DIR/conf.d/zz-preload.ini

# Caddy needs to bind :80 without root
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
 && chown -R www-data:www-data /config/caddy /data/caddy

USER www-data

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s \
  CMD wget -q --spider http://127.0.0.1:2019/config/ || exit 1
