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
# Phase 0O.6A (ADR 0052 section 3.2): every base image is pinned BY DIGEST
# (`image:exact-version@sha256:...`); the tag is a readable alias only and
# the toolchain (PHP 8.3.35, Composer 2.10.2, Node 22.23.3 / npm 10.9.9) is
# whatever these digests contain. Phase 0O.6B: the PHP base (extension build,
# Composer install and runtime stages) is Debian 13 "trixie"; the Node and
# Composer images are build-only and unchanged, so npm/Composer output is
# unchanged. A digest update is an ordinary reviewed
# change that re-runs full release qualification (ADR 0052 section 3.3);
# Tests\Feature\Configuration\SupplyChainGuardTest refuses a tag-only base.
# Release builds never pass --build-arg for these.

ARG PHP_IMAGE=php:8.3.35-fpm-trixie@sha256:e0623b713dba09e154cd513c9d175ba0967b82dae101f0c817a7e812b4b1c5d4
ARG NODE_IMAGE=node:22.23.3-bookworm-slim@sha256:43ac6c60b8f89723f746e8a92ce91abd5017e627ce1ddfe4238355d3a30b772c
ARG COMPOSER_IMAGE=composer:2.10.2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040

FROM ${COMPOSER_IMAGE} AS composer-bin

# --- PHP extensions (compiled once; only the built artefacts move on) -----
FROM ${PHP_IMAGE} AS php-ext
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libpng-dev libzip-dev unzip \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gd opcache pcntl zip \
 && rm -rf /var/lib/apt/lists/*

# --- Production PHP dependencies ------------------------------------------
# From composer.lock only (`install`, never `update`); no Composer plugin and
# no package script runs (ADR 0052 sections 3.5, 3.18). Laravel's own package
# discovery is the one explicit step.
FROM php-ext AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-plugins --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-plugins --no-interaction \
 && APP_ENV=build php artisan package:discover --ansi

# --- Frontend assets --------------------------------------------------------
# .npmrc is copied BEFORE `npm ci` so its ignore-scripts=true applies: no
# dependency lifecycle script runs; the build itself is the explicit
# `npm run build` below (ADR 0052 section 3.5).
FROM ${NODE_IMAGE} AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY . .
# Tailwind's @source reads the framework's pagination views.
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# --- Runtime ------------------------------------------------------------------
# Phase 0O.6B (runtime package minimization): the official PHP image keeps
# its extension build toolchain ($PHPIZE_DEPS: gcc, g++, binutils, make,
# autoconf, dpkg-dev + perl, pkg-config, re2c, file), the libc headers, and
# the curl CLI / xz-utils it used to fetch PHP's source. None is needed at
# run time (extensions are compiled in the php-ext stage), so they are
# purged. The libraries PHP itself links (libcurl, libxml2, libsqlite3,
# libonig, libargon2, libsodium, libreadline, OpenSSL, zlib) are marked
# manually installed by the base image and stay; `php -m` is verified by
# verify-images.sh.
FROM ${PHP_IMAGE} AS runtime
RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx libpq5 libpng16-16t64 libzip5 ca-certificates \
 && apt-get purge -y --auto-remove $PHPIZE_DEPS libc6-dev curl xz-utils \
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
    LOG_FORMAT=json \
    LOG_LEVEL=info \
    DB_CONNECTION=pgsql \
    APP_MAINTENANCE_DRIVER=cache \
    APP_MAINTENANCE_STORE=database \
    LOG_CHANNEL=stderr \
    REDIS_CLIENT=predis

USER www-data
# 8080: public web (behind the TLS proxy); 9102: private metrics (collector only).
EXPOSE 8080 9102
ENTRYPOINT ["lycenza"]
CMD ["web"]
