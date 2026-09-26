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
#
# Phase 0O.6D (owner decision; docs/operations/CUSTOM-PHP-RUNTIME.md): the
# runtime PHP is REPOSITORY-BUILT. Debian 13 ships libcurl 8.14.1 and libxml2
# 2.9.14 with unfixed CRITICAL/HIGH advisories; the fixed upstream releases
# are curl 8.22.0 (libcurl.so.4) and libxml2 2.15.4 (libxml2.so.16, a new
# ABI the official PHP binary cannot load). So PHP 8.3.35 is compiled from
# the official, signature-verified release source with the official
# docker-library recipe (same configure flags, minus PEAR -- dev tooling
# whose install downloads an unverified phar) against curl and libxml2 built
# from verified upstream sources. The official PHP image is now a BUILD-ONLY
# stage (toolchain, recipe scripts); the runtime starts from the same Debian
# 13 base image and carries no Debian libcurl/libxml2. Lycenza owns rebuilding
# this runtime for every PHP 8.3 / curl / libxml2 / base security release.
# Tests\Feature\Configuration\CustomPhpRuntimeGuardTest guards this file.

ARG PHP_IMAGE=php:8.3.35-fpm-trixie@sha256:e0623b713dba09e154cd513c9d175ba0967b82dae101f0c817a7e812b4b1c5d4
ARG RUNTIME_IMAGE=debian:13.7-slim@sha256:a99cfc517144bc59b1978475ec53b46ecabec7e43635402ee5b77cc54cd1b20a
ARG NODE_IMAGE=node:22.23.3-bookworm-slim@sha256:43ac6c60b8f89723f746e8a92ce91abd5017e627ce1ddfe4238355d3a30b772c
ARG COMPOSER_IMAGE=composer:2.10.2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040

FROM ${COMPOSER_IMAGE} AS composer-bin

# --- Verified upstream sources (Phase 0O.6D, R9) -----------------------------
# Exact releases, pinned SHA-256, and the detached signature checked against
# PINNED key fingerprints where the project publishes one (PHP: the three
# PHP 8.3 release managers, keys from php.net's published keyring; curl: the
# release key from its author's site) -- whatever the key source, only a
# VALIDSIG from a pinned fingerprint passes. GNOME
# publishes no signature for libxml2: its pinned SHA-256 (equal to GNOME's
# published .sha256sum) is the integrity mechanism. Nothing is piped to a
# shell; a mismatch fails the build.
FROM ${PHP_IMAGE} AS sources
# (The LYCENZA_*_URL / *_SHA256 pairs are also read by the release
# provenance generator as the image's source materials.)
ENV LYCENZA_PHP_VERSION=8.3.35 \
    LYCENZA_PHP_URL=https://www.php.net/distributions/php-8.3.35.tar.xz \
    LYCENZA_PHP_SHA256=ff4630fbbbd94359134b7d3c223db59329905bdc4f5a9ef93d257b48e358619a \
    LYCENZA_PHP_SIGNER_FINGERPRINTS="1198C0117593497A5EC5C199286AF1F9897469DC C28D937575603EB4ABB725861C0779DC5C0A9DE4 AFD8691FDAEDF03BDF6E460563F15A9B715376CA" \
    LYCENZA_CURL_VERSION=8.22.0 \
    LYCENZA_CURL_URL=https://curl.se/download/curl-8.22.0.tar.xz \
    LYCENZA_CURL_SHA256=f7ef3ae8a22e521f289803fe93543eb64c329b58aa73a9e224dfd915a2a5f4f7 \
    LYCENZA_CURL_SIGNER_FINGERPRINT=27EDEAF22F3ABCEB50DB9A125CC908FDB71E12C2 \
    LYCENZA_LIBXML2_VERSION=2.15.4 \
    LYCENZA_LIBXML2_URL=https://download.gnome.org/sources/libxml2/2.15/libxml2-2.15.4.tar.xz \
    LYCENZA_LIBXML2_SHA256=98087fd181d9070724f3fbc65c7377db03038eb92bd882374daff44940138821
RUN set -eux; \
    apt-get update; apt-get install -y --no-install-recommends gnupg; rm -rf /var/lib/apt/lists/*; \
    mkdir -p /usr/src/lycenza; cd /usr/src/lycenza; \
    curl -fsSL --proto '=https' -o php.tar.xz "$LYCENZA_PHP_URL"; \
    curl -fsSL --proto '=https' -o php.tar.xz.asc "$LYCENZA_PHP_URL.asc"; \
    curl -fsSL --proto '=https' -o curl.tar.xz "$LYCENZA_CURL_URL"; \
    curl -fsSL --proto '=https' -o curl.tar.xz.asc "$LYCENZA_CURL_URL.asc"; \
    curl -fsSL --proto '=https' -o curl-release-key.asc https://daniel.haxx.se/mykey.asc; \
    curl -fsSL --proto '=https' -o libxml2.tar.xz "$LYCENZA_LIBXML2_URL"; \
    printf '%s *php.tar.xz\n%s *curl.tar.xz\n%s *libxml2.tar.xz\n' "$LYCENZA_PHP_SHA256" "$LYCENZA_CURL_SHA256" "$LYCENZA_LIBXML2_SHA256" | sha256sum -c -; \
    export GNUPGHOME="$(mktemp -d)"; \
    curl -fsSL --proto '=https' -o php-keyring.gpg https://www.php.net/distributions/php-keyring.gpg; \
    gpg --batch --import php-keyring.gpg; \
    gpg --batch --status-fd 1 --verify php.tar.xz.asc php.tar.xz > php.verify; \
    printf '%s\n' $LYCENZA_PHP_SIGNER_FINGERPRINTS > php.keys; \
    awk '$2 == "VALIDSIG" { print $12 }' php.verify | grep -Fqx -f php.keys; \
    gpg --batch --import curl-release-key.asc; \
    gpg --batch --status-fd 1 --verify curl.tar.xz.asc curl.tar.xz > curl.verify; \
    awk '$2 == "VALIDSIG" { print $12 }' curl.verify | grep -Fqx "$LYCENZA_CURL_SIGNER_FINGERPRINT"; \
    gpgconf --kill all; rm -rf "$GNUPGHOME" *.asc *.verify *.keys php-keyring.gpg

# --- Native security libraries + PHP (repository-built) -----------------------
# The official image's recipe environment (PHP_CFLAGS/CPPFLAGS/LDFLAGS,
# PHPIZE_DEPS, docker-php-source/-ext-*) is reused as-is. Debian's libcurl
# and libxml2 (and the curl CLI that needs them) are purged first and their
# -dev packages are never installed, so neither configure nor the linker can
# fall back to them.
FROM ${PHP_IMAGE} AS php-build
COPY --from=sources /usr/src/lycenza /usr/src/lycenza
RUN set -eux; \
    apt-get update; \
    apt-get purge -y curl libcurl4t64 libxml2; \
    apt-get install -y --no-install-recommends \
        libargon2-dev libonig-dev libreadline-dev libsodium-dev libsqlite3-dev libssl-dev zlib1g-dev \
        libnghttp2-dev libidn2-dev libpsl-dev \
        libpq-dev libpng-dev libzip-dev unzip; \
    ! dpkg -s libcurl4t64 libxml2 libcurl4-openssl-dev libxml2-dev >/dev/null 2>&1; \
    rm -rf /var/lib/apt/lists/*; \
    export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS"; \
    cd /usr/src/lycenza; \
    tar -xf libxml2.tar.xz; cd libxml2-*/; \
    ./configure --prefix=/usr/local --disable-static --without-python; \
    make -j"$(nproc)"; make install; cd ..; \
    # the official recipe links /usr/local/include/curl to Debian's (now purged) headers
    test ! -e /usr/local/include/curl || test -L /usr/local/include/curl; rm -f /usr/local/include/curl; \
    tar -xf curl.tar.xz; cd curl-*/; \
    ./configure --prefix=/usr/local --disable-static --enable-shared \
        --with-openssl --with-nghttp2 --with-zlib --with-libidn2 --with-libpsl \
        --without-brotli --without-zstd --without-libssh2 --without-librtmp --without-gssapi \
        --disable-ldap --disable-ldaps --disable-rtsp --disable-dict --disable-telnet --disable-tftp \
        --disable-pop3 --disable-imap --disable-smb --disable-smtp --disable-gopher --disable-mqtt \
        --disable-ftp --disable-file --disable-websockets --disable-manual \
        --with-ca-bundle=/etc/ssl/certs/ca-certificates.crt --with-ca-path=/etc/ssl/certs; \
    make -j"$(nproc)"; make install; cd ..; \
    ldconfig; \
    rm -rf /usr/local/bin/php /usr/local/sbin/php-fpm /usr/local/lib/php /usr/local/include/php; \
    cp php.tar.xz /usr/src/php.tar.xz; \
    export PKG_CONFIG_PATH=/usr/local/lib/pkgconfig PHP_BUILD_PROVIDER='https://github.com/mawk-khan/lycenza' PHP_UNAME='Linux - Docker'; \
    docker-php-source extract; cd /usr/src/php; \
    gnuArch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)"; \
    debMultiarch="$(dpkg-architecture --query DEB_BUILD_MULTIARCH)"; \
    ./configure \
        --build="$gnuArch" \
        --sysconfdir="${PHP_INI_DIR%/php}" \
        --with-config-file-path="$PHP_INI_DIR" \
        --with-config-file-scan-dir="$PHP_INI_DIR/conf.d" \
        --enable-option-checking=fatal \
        --with-mhash --with-pic \
        --enable-mbstring --enable-mysqlnd --with-password-argon2 --with-sodium=shared \
        --with-pdo-sqlite=/usr --with-sqlite3=/usr \
        --with-curl --with-iconv --with-openssl --with-readline --with-zlib \
        --disable-phpdbg \
        --with-libdir="lib/$debMultiarch" \
        --disable-cgi \
        --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data \
        | tee /usr/src/lycenza/php-configure.log; \
    make -j"$(nproc)"; \
    find -type f -name '*.a' -delete; \
    make install; \
    cp -v php.ini-* "$PHP_INI_DIR/"; \
    cd /; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gd opcache pcntl zip; \
    docker-php-source delete; \
    find /usr/local -type f -perm '/0111' -exec strip --strip-all '{}' + 2>/dev/null || :; \
    # Standard ELF package metadata (FDO .note.package, the systemd "ELF
    # package metadata" spec) so SBOM tools identify the source-built
    # libraries -- written after stripping, from the pinned versions.
    for lib in "curl:8.22.0:/usr/local/lib/libcurl.so.4:https://curl.se/download/curl-8.22.0.tar.xz" \
               "libxml2:2.15.4:/usr/local/lib/libxml2.so.16:https://download.gnome.org/sources/libxml2/2.15/libxml2-2.15.4.tar.xz"; do \
        name="${lib%%:*}"; rest="${lib#*:}"; version="${rest%%:*}"; rest="${rest#*:}"; path="${rest%%:*}"; url="${rest#*:}"; \
        php -r '$d = json_encode(["type" => "generic", "name" => $argv[2], "version" => $argv[3], "architecture" => "x86_64", "sourceRepo" => $argv[4]], JSON_UNESCAPED_SLASHES) . "\0"; $n = "FDO\0"; $p = fn ($s) => $s . str_repeat("\0", (4 - strlen($s) % 4) % 4); file_put_contents($argv[1], pack("VVV", strlen($n), strlen($d), 0xcafe1a7e) . $p($n) . $p($d));' /tmp/note "$name" "$version" "$url"; \
        objcopy --add-section .note.package=/tmp/note --set-section-flags .note.package=noload,readonly "$path"; \
        readelf -n "$path" | grep -q "\"name\":\"$name\",\"version\":\"$version\""; \
    done; rm -f /tmp/note; \
    php -r 'exit(curl_version()["version"] === "8.22.0" ? 0 : 1);'; \
    php -r 'exit(LIBXML_DOTTED_VERSION === "2.15.4" ? 0 : 1);'; \
    ldd /usr/local/bin/php | grep -q '/usr/local/lib/libxml2.so.16'; \
    ldd /usr/local/bin/php | grep -q '/usr/local/lib/libcurl.so.4'; \
    ! ldd /usr/local/bin/php | grep -q 'not found'; \
    php --version

# --- Production PHP dependencies ------------------------------------------
# From composer.lock only (`install`, never `update`); no Composer plugin and
# no package script runs (ADR 0052 sections 3.5, 3.18). Laravel's own package
# discovery is the one explicit step.
FROM php-build AS vendor
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
# Plain Debian 13 (the official PHP image's own base) plus only what the
# repository-built PHP, nginx and TLS need at run time. No compiler, headers,
# source tree, curl CLI, PEAR, Debian libcurl or Debian libxml2. The patched
# libcurl.so.4 / libxml2.so.16 live in /usr/local/lib, registered with the
# dynamic loader explicitly (ld.so.conf.d + ldconfig). STOPSIGNAL, PHP_INI_DIR
# and the FPM configuration layout reproduce the official image.
FROM ${RUNTIME_IMAGE} AS runtime
ENV PHP_INI_DIR=/usr/local/etc/php
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        ca-certificates nginx \
        libargon2-1 libonig5 libreadline8t64 libsodium23 libsqlite3-0 libssl3t64 zlib1g \
        libnghttp2-14 libidn2-0 libpsl5t64 \
        libpq5 libpng16-16t64 libzip5 \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default
COPY --from=php-build /usr/local/bin/php /usr/local/bin/php
COPY --from=php-build /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm
COPY --from=php-build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=php-build /usr/local/etc /usr/local/etc
COPY --from=php-build /usr/local/lib/libcurl.so.4 /usr/local/lib/libcurl.so.4
COPY --from=php-build /usr/local/lib/libxml2.so.16 /usr/local/lib/libxml2.so.16
COPY deploy/php/production.ini /usr/local/etc/php/conf.d/zz-lycenza-production.ini
COPY deploy/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-lycenza-pool.conf
COPY deploy/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=0755 deploy/entrypoint.sh /usr/local/bin/lycenza
# Phase 0O.6F (runtime security contract, infrastructure/release/runtime-security.json):
# nothing in the image mounts filesystems, so mount/umount lose their setuid
# bit through Debian's own dpkg-statoverride (recorded in the dpkg database;
# no Essential file is removed).
RUN dpkg-statoverride --update --add root root 0755 /usr/bin/mount \
 && dpkg-statoverride --update --add root root 0755 /usr/bin/umount
RUN echo /usr/local/lib > /etc/ld.so.conf.d/00-lycenza-native.conf \
 && ldconfig \
 && ! ldd /usr/local/bin/php /usr/local/sbin/php-fpm /usr/local/lib/php/extensions/*/*.so | grep -q 'not found' \
 && php --version

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
# PHP-FPM's graceful shutdown signal, as in the official image.
STOPSIGNAL SIGQUIT
ENTRYPOINT ["lycenza"]
CMD ["web"]
