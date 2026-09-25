#!/bin/sh
# Phase 0O.4A (ADR 0050 sections 3, 12): the production image's role
# selector. One immutable image, several roles:
#
#   web                 nginx + PHP-FPM (port 8080); exits if either dies
#   worker <queue>      queue:work for exactly one queue
#   scheduler           the ONE schedule:work process of a deployment
#   console <args...>   a trusted operator/release artisan command
#
# Long-running roles first build the configuration, route and view caches
# from the injected environment -- the production configuration guard
# (Phase 0O.1/0O.4A) refuses to boot on unsafe configuration before any
# traffic or job is served. No role here receives or needs DB_ADMIN_*;
# only `console` invocations for the release step do (ADR 0050 section 4).
set -eu

cd /var/www/app

warm_caches() {
    php artisan config:cache --no-interaction >/dev/null
    php artisan route:cache --no-interaction >/dev/null
    php artisan view:cache --no-interaction >/dev/null
}

role="${1:-web}"
[ "$#" -gt 0 ] && shift

case "$role" in
    web)
        warm_caches
        php-fpm --nodaemonize &
        fpm=$!
        nginx -g 'daemon off;' &
        web=$!
        # A stop signal drains both gracefully (QUIT: finish in-flight
        # requests) instead of being ignored by a PID-1 shell.
        trap 'kill -QUIT "$fpm" "$web" 2>/dev/null; wait; exit 0' TERM INT
        # Exit (and let the platform restart the container) as soon as
        # either process stops; never keep serving half a web role.
        while kill -0 "$fpm" 2>/dev/null && kill -0 "$web" 2>/dev/null; do
            sleep 2
        done
        kill "$fpm" "$web" 2>/dev/null || true
        exit 1
        ;;
    worker)
        queue="${1:?worker needs a queue name (default, integrations or notifications)}"
        # Exactly the queues the application dispatches to (deploy/processes.json,
        # Tests\Feature\Configuration\ProductionProcessManifestTest).
        case "$queue" in
            default|integrations|notifications) ;;
            *) echo "unknown queue: $queue" >&2; exit 64 ;;
        esac
        warm_caches
        exec php artisan queue:work --queue="$queue" --sleep=3 --timeout=60 --max-time=3600 --no-interaction
        ;;
    scheduler)
        warm_caches
        exec php artisan schedule:work --no-interaction
        ;;
    console)
        exec php artisan "$@"
        ;;
    *)
        echo "unknown role: $role (web | worker <queue> | scheduler | console <artisan args>)" >&2
        exit 64
        ;;
esac
