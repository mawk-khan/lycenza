# syntax=docker/dockerfile:1
#
# Phase 0O.4A (ADR 0050 section 12): the PRODUCTION application image.
# One immutable image for every application role -- web (nginx + PHP-FPM),
# queue workers, the scheduler and the trusted operator console -- selected
# by the command (apps/platform/deploy/entrypoint.sh). No secrets, no .env,
# no tests, no dev dependencies, no demo seed data; configuration and
# secrets are injected at run time (ADR 0050 section 4).
#
# Build (context = apps/platform):
#   docker build -f infrastructure/docker/production/app.Dockerfile \
#     -t lycenza-app:<version> apps/platform
#
# Base images are pinned by tag; digest pinning follows the future O16
# supply-chain policy.

ARG PHP_IMAGE=php:8.3-fpm-bookworm
ARG NODE_IMAGE=node:22-bookworm-slim
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer-bin

# --- PHP extensions (compiled once; only the built artefacts move on) -----
FROM ${PHP_IMAGE} AS php-ext
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libpng-dev libzip-dev unzip \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gd opcache pcntl zip \
 && rm -rf /var/lib/apt/lists/*

# --- Production PHP dependencies ------------------------------------------
FROM php-ext AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction \
 && APP_ENV=build php artisan package:discover --ansi

# --- Frontend assets --------------------------------------------------------
FROM ${NODE_IMAGE} AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# Tailwind's @source reads the framework's pagination views.
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# --- Runtime ------------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime
RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx libpq5 libpng16-16 libzip4 ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default
COPY --from=php-ext /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=php-ext /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY deploy/php/production.ini /usr/local/etc/php/conf.d/zz-lycenza-production.ini
COPY deploy/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-lycenza-pool.conf
COPY deploy/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=0755 deploy/entrypoint.sh /usr/local/bin/lycenza

WORKDIR /var/www/app
COPY --from=vendor --chown=www-data:www-data /app /var/www/app
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/app/public/build
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /tmp/nginx \
 && chown -R www-data:www-data storage bootstrap/cache /tmp/nginx \
 && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php \
 && rm -rf apps docs resources/js resources/css phpstan.neon eslint.config.js tsconfig.json vite.config.ts package.json package-lock.json README.md

# predis is the Redis client (a Composer dependency; no phpredis extension
# is compiled in), exactly as in DDEV. Maintenance mode is shared by every
# container through PostgreSQL's cache table (never a per-container file,
# and it survives a Redis loss) -- ProductionConfigurationGuard refuses a
# `file` driver.
ENV APP_ENV=production \
    DB_CONNECTION=pgsql \
    APP_MAINTENANCE_DRIVER=cache \
    APP_MAINTENANCE_STORE=database \
    LOG_CHANNEL=stderr \
    REDIS_CLIENT=predis

USER www-data
EXPOSE 8080
ENTRYPOINT ["lycenza"]
CMD ["web"]
