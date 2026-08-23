# Local development image for apps/platform (Laravel).
# NOT a production image: no multi-stage build, no opcache preload,
# no non-root hardening beyond the base image defaults. Production
# packaging is out of scope for Phase 0A (see docs/roadmap/MASTER-ROADMAP.md).

FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql pgsql bcmath zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

EXPOSE 8000

# --no-reload is required, not optional, in this container: without it
# `artisan serve` only forwards a small built-in allowlist of env vars
# to the actual request-handling child process (Illuminate\Foundation\
# Console\ServeCommand::shouldPassThroughEnvironmentVariable), silently
# discarding docker-compose's DB_HOST/REDIS_HOST/AWS_ENDPOINT overrides
# and falling back to apps/platform/.env's plain-localhost defaults --
# confirmed by reproducing it directly against a running container
# during this checkpoint's verification (see the Phase 0B final
# report's "PostgreSQL Isolation Proof" / technical-debt notes).
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
