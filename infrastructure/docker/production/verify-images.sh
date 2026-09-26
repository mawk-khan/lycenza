#!/usr/bin/env bash
#
# Phase 0O.4A (ADR 0050 section 12): builds (optionally) and verifies the
# two production images LOCALLY. It deploys nothing, pushes nothing and uses
# no real secret: every credential below is a random throwaway generated for
# this run, the database host is an unroutable TEST-NET address (a real
# unreachable database, no DNS involved), and the only
# dependency started is a throwaway password-protected Redis container on a
# private network that is removed on exit.
#
# Usage (from the repository root):
#   infrastructure/docker/production/verify-images.sh [--build]
#   APP_IMAGE=lycenza-app:x AI_IMAGE=lycenza-ai-gateway:x infrastructure/docker/production/verify-images.sh
#
# Checks:
#   app image   -- non-root; no .env, tests, PHPUnit config, dev Composer
#                  packages, node_modules, demo seeders (DemoEnvironmentGuard
#                  excepted), test-database reset command or cached config;
#                  no committed local credential or demo password anywhere
#                  (the development token and placeholder names only inside
#                  ProductionConfigurationGuard, which refuses them); built
#                  assets present; OPcache timestamps off; the web role
#                  serves liveness through nginx + PHP-FPM as www-data, does
#                  not expose PHP or nginx versions, never serves a dotfile or
#                  a PHP file other than the front controller; an unsafe
#                  configuration refuses to start without printing a value;
#                  worker/scheduler roles start; no probe/demo route is
#                  registered in production; unknown roles are refused.
#   gateway     -- non-root; no .env, tests or dev requirements; refuses to
#                  start without a service token or with the development
#                  token; with a token: live 200 and ready 200, no external
#                  provider enabled.
#   supply chain   -- (Phase 0O.6A) no Composer/Node/npm in the application
#                  runtime; no private-key file in application paths; the
#                  Gateway's installed packages are exactly
#                  services/ai/requirements.lock; no compiler in the Gateway.
#
# Where this fits (ADR 0052): verify-images.sh proves how the images BEHAVE
# and is one gate inside release qualification
# (infrastructure/release/qualify, stage `verify-images`, run against the
# exact images built from the OCI archives). The ARTIFACT -- digest, SBOM,
# vulnerability policy, secret/history scans, provenance, signature, source
# lineage -- is judged only by infrastructure/release/verify-artifact. A
# pass here never makes an image releasable on its own.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
APP_IMAGE="${APP_IMAGE:-lycenza-app:verify}"
AI_IMAGE="${AI_IMAGE:-lycenza-ai-gateway:verify}"
RUN_ID="lycenza-verify-$$"
NETWORK="${RUN_ID}-net"
failures=0

pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; failures=$((failures + 1)); }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then pass "$name"; else fail "$name"; fi; }

cleanup() {
    docker ps -aq --filter "name=${RUN_ID}" | xargs -r docker rm -f >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

build() {
    # `docker buildx build --load` works where the classic builder's
    # BuildKit plugin path is broken; --chmod on COPY needs BuildKit.
    docker buildx build --builder default --load -f "$ROOT/infrastructure/docker/production/app.Dockerfile" -t "$APP_IMAGE" "$ROOT/apps/platform"
    docker buildx build --builder default --load -f "$ROOT/infrastructure/docker/production/ai.Dockerfile" -t "$AI_IMAGE" "$ROOT/services/ai"
}

[[ "${1:-}" == "--build" ]] && build

random() { head -c 32 /dev/urandom | base64 | tr -d '/+=' | cut -c1-40; }

REDIS_PASSWORD="$(random)"
METRICS_TOKEN="$(random)$(random)"
APP_ENV_ARGS=(
    -e METRICS_SCRAPE_TOKEN="$METRICS_TOKEN"
    -e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    -e APP_URL=https://verify.invalid
    -e APP_DEBUG=false
    -e SESSION_SECURE_COOKIE=true
    -e AI_GATEWAY_CONTEXT_SIGNING_KEY="$(random)"
    -e AI_GATEWAY_SERVICE_TOKEN="$(random)"
    -e DB_HOST=192.0.2.10 -e DB_SSLMODE=require
    -e DB_USERNAME=school_os_app -e DB_PASSWORD="$(random)"
    -e REDIS_HOST="${RUN_ID}-redis" -e REDIS_PASSWORD="$REDIS_PASSWORD"
    -e CACHE_STORE=redis -e SESSION_DRIVER=redis -e QUEUE_CONNECTION=redis
    -e DOCUMENTS_DISK=s3 -e COMMUNICATION_ATTACHMENTS_DISK=s3
    -e AWS_BUCKET=lycenza-verify -e AWS_DEFAULT_REGION=us-east-1 -e AWS_ENDPOINT=https://objects.invalid
    -e TRUSTED_PROXIES=10.0.0.0/8
)

in_app() { docker run --rm --entrypoint sh "$APP_IMAGE" -c "$1"; }

echo "== app image: $APP_IMAGE"
check "app runs as a non-root user" test "$(in_app 'id -u')" != "0"
check "no .env file anywhere in the application" test -z "$(in_app 'find /var/www/app -name ".env*" -not -path "*/vendor/*"')"
check "no tests or PHPUnit configuration" in_app 'test ! -e /var/www/app/tests && test ! -e /var/www/app/phpunit.xml'
check "no dev Composer packages" in_app 'test ! -e /var/www/app/vendor/phpunit && test ! -e /var/www/app/vendor/mockery && test ! -e /var/www/app/vendor/larastan'
check "no node_modules" in_app 'test ! -e /var/www/app/node_modules'
check "demo seeders excluded (guard only)" test "$(in_app 'ls /var/www/app/database/seeders/Demo')" = "DemoEnvironmentGuard.php"
check "test-database reset command excluded" in_app 'test ! -e /var/www/app/app/Console/Commands/ResetTestDatabase.php'
check "no cached configuration or routes baked in" in_app 'test ! -e /var/www/app/bootstrap/cache/config.php && ! ls /var/www/app/bootstrap/cache/routes-*.php'
check "built assets present" in_app 'test -s /var/www/app/public/build/manifest.json'
check "no demo password or committed local credential" test -z "$(in_app 'grep -rlF -e "Demo1234!" -e "school_os_app_local_only_password" /var/www/app /usr/local/etc 2>/dev/null')"
leaked="$(in_app 'grep -rlF -e "dev-local-only-token" -e "school_os_secret" /var/www/app --include=*.php 2>/dev/null' | grep -v '^/var/www/app/app/Support/Configuration/ProductionConfigurationGuard.php$' || true)"
check "development token/placeholder only in the guard that refuses them" test -z "$leaked"
check "OPcache timestamp validation off" in_app 'php -i | grep -q "opcache.validate_timestamps => Off"'
check "PHP does not expose itself" in_app 'php -i | grep -q "expose_php => Off"'
check "stack traces carry no arguments (zend.exception_ignore_args)" in_app 'php -i | grep -q "zend.exception_ignore_args => On"'
check "production logs default to structured JSON at info" in_app 'test "$LOG_FORMAT" = json && test "$LOG_LEVEL" = info'
check "metrics front controller exists outside public/" in_app 'test -f /var/www/app/metrics/index.php && test ! -e /var/www/app/public/metrics.php'
check "no Composer, Node or npm in the runtime image" in_app '! command -v composer && ! command -v node && ! command -v npm'
check "no private-key file in application or configuration paths" test -z "$(in_app 'find /var/www/app /usr/local/etc /etc/nginx \( -name "*.pem" -o -name "*.key" -o -name "id_rsa*" \) -not -path "*/vendor/*" 2>/dev/null')"

docker network create "$NETWORK" >/dev/null
docker run -d --name "${RUN_ID}-redis" --network "$NETWORK" redis:7-alpine redis-server --requirepass "$REDIS_PASSWORD" --save '' --appendonly no >/dev/null

unsafe_output="$(docker run --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e APP_KEY=canary-unsafe-app-key "$APP_IMAGE" web 2>&1 || true)"
check "unsafe configuration refuses to start" grep -q "Refusing to start" <<<"$unsafe_output"
check "refusal never prints the value" test -z "$(grep -F canary-unsafe-app-key <<<"$unsafe_output")"

docker run -d --name "${RUN_ID}-web" --network "$NETWORK" "${APP_ENV_ARGS[@]}" "$APP_IMAGE" web >/dev/null
http() { docker exec "${RUN_ID}-web" php -r '$c=stream_context_create(["http"=>["ignore_errors"=>true,"header"=>$argv[2] ?? ""]]); $b=@file_get_contents("http://127.0.0.1:8080".$argv[1],false,$c); echo implode("\n",$http_response_header ?? []),"\n\n",$b;' "$@"; }
for _ in $(seq 1 30); do http /api/health/live 2>/dev/null | grep -q '200' && break; docker exec "${RUN_ID}-web" true 2>/dev/null || break; sleep 1; done
live="$(http /api/health/live 2>/dev/null || true)"
check "web liveness 200 through nginx + PHP-FPM" grep -q "HTTP/1.1 200" <<<"$live"
check "no X-Powered-By header" test -z "$(grep -i '^x-powered-by' <<<"$live")"
check "nginx does not expose its version" test -z "$(grep -i '^server:.*[0-9]' <<<"$live")"
check "security headers present" grep -qi '^x-content-type-options: nosniff' <<<"$live"
started=$(date +%s); ready="$(http /api/health/ready 2>/dev/null || true)"; took=$(( $(date +%s) - started ))
check "readiness fails closed (503) without a database" grep -q "HTTP/1.1 503" <<<"$ready"
check "an unreachable database is detected within 15 s (DB_CONNECT_TIMEOUT), not a hung worker" test "$took" -le 15
check "dotfiles never served" grep -qE "HTTP/1.1 (403|404)" <<<"$(http /.env 2>/dev/null || true)"
check "only the front controller executes" grep -q "HTTP/1.1 404" <<<"$(http /artisan.php 2>/dev/null || true)"
check "web processes run as www-data" test -z "$(docker top "${RUN_ID}-web" -o user,comm | awk 'NR>1 && $2 ~ /php-fpm|nginx/ && $1 != "www-data" && $1 != "33"' | grep -v 'master' || true)"
check "spoofed X-Forwarded-Proto from an untrusted peer earns no HSTS" test -z "$(http /api/health/live 'X-Forwarded-Proto: https' 2>/dev/null | grep -i '^strict-transport-security' || true)"

# The same image with its own loopback trusted (as a sidecar proxy would be).
docker run -d --name "${RUN_ID}-web-proxied" --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e TRUSTED_PROXIES=127.0.0.1/32 "$APP_IMAGE" web >/dev/null
proxied() { docker exec "${RUN_ID}-web-proxied" php -r '$c=stream_context_create(["http"=>["ignore_errors"=>true,"header"=>"X-Forwarded-Proto: https"]]); @file_get_contents("http://127.0.0.1:8080/api/health/live",false,$c); echo implode("\n",$http_response_header ?? []);'; }
for _ in $(seq 1 30); do proxied 2>/dev/null | grep -q '200' && break; sleep 1; done
check "HSTS through a trusted proxy" grep -qi '^strict-transport-security: max-age=31536000$' <<<"$(proxied 2>/dev/null | tr -d '\r' || true)"

# Phase 0O.5A (ADR 0051): structured logs and the private metrics listener.
log_line="$(docker run --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e PROCESS_ROLE=console --entrypoint php "$APP_IMAGE" -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\Log::info("verify.images.structured", ["password" => "canary-image-pw"]);' 2>&1 | grep '"event_code":"verify.images.structured"' || true)"
check "production logs are one JSON object per line" test -n "$log_line"
check "log lines carry the fixed fields" grep -q '"service":"platform","process_role":"console","environment":"production"' <<<"$log_line"
check "the log pipeline redacts inside the image" test -z "$(grep canary-image-pw <<<"$log_line")"

metrics() { docker exec "${RUN_ID}-web" php -r '$c=stream_context_create(["http"=>["ignore_errors"=>true,"timeout"=>60,"header"=>$argv[2] ?? ""]]); $b=@file_get_contents("http://127.0.0.1:".$argv[1]."/metrics",false,$c); echo implode("\n",$http_response_header ?? []),"\n\n",$b;' "$@"; }
# (With the database deliberately unreachable every public request fails;
# what matters is that the public listener never returns the exposition.)
public_metrics="$(metrics 8080 "Authorization: Bearer $METRICS_TOKEN" 2>/dev/null || true)"
check "the public listener never serves metrics, even with the token" test -z "$(grep -E 'HTTP/1.1 200|lycenza_' <<<"$public_metrics")"
check "the private listener refuses a missing token" grep -q "HTTP/1.1 401" <<<"$(metrics 9102 2>/dev/null || true)"
check "the private listener refuses a wrong token" grep -q "HTTP/1.1 401" <<<"$(metrics 9102 "Authorization: Bearer wrong-token-value" 2>/dev/null || true)"
check "the private listener serves nothing but /metrics" grep -q "HTTP/1.1 404" <<<"$(docker exec "${RUN_ID}-web" php -r '$c=stream_context_create(["http"=>["ignore_errors"=>true]]); @file_get_contents("http://127.0.0.1:9102/api/health/live",false,$c); echo $http_response_header[0] ?? "";' 2>/dev/null || true)"
private="$(metrics 9102 "Authorization: Bearer $METRICS_TOKEN" 2>/dev/null || true)"
check "the private listener serves the exposition with the token" grep -q "# TYPE lycenza_readiness_status gauge" <<<"$private"
check "metrics work with no backend and an unreachable database" grep -q 'lycenza_readiness_status{dependency="postgresql"} 0' <<<"$private"
check "the exposition carries no identifier" test -z "$(grep -E '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-' <<<"$private")"

routes="$(docker run --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" "$APP_IMAGE" console route:list --json 2>/dev/null || true)"
check "production route list builds" grep -q '"uri"' <<<"$routes"
check "no probe or demo route in production" test -z "$(grep -oE '"uri":"[^"]*(probe|demo)[^"]*"' <<<"$routes")"
check "configuration and routes cache in production mode" docker run --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" --entrypoint sh "$APP_IMAGE" -c 'php artisan config:cache && php artisan route:cache && php artisan route:list >/dev/null'

for role in "worker default" "worker integrations" "worker notifications" scheduler; do
    name="${RUN_ID}-$(tr ' ' '-' <<<"$role")"
    # shellcheck disable=SC2086
    docker run -d --name "$name" --network "$NETWORK" "${APP_ENV_ARGS[@]}" "$APP_IMAGE" $role >/dev/null
done
sleep 5
for role in worker-default worker-integrations worker-notifications scheduler; do
    check "$role role stays running" test "$(docker inspect -f '{{.State.Running}}' "${RUN_ID}-${role}")" = "true"
done
set +e; docker run --rm "$APP_IMAGE" bogus >/dev/null 2>&1; unknown=$?; set -e
check "unknown role refused (exit 64)" test "$unknown" = "64"

echo "== gateway image: $AI_IMAGE"
in_ai() { docker run --rm --entrypoint sh "$AI_IMAGE" -c "$1"; }
check "gateway runs as a non-root user" test "$(in_ai 'id -u')" != "0"
locked="$(python3 - "$ROOT/services/ai/requirements.lock" <<'PY'
import re, sys
pins = re.findall(r"^([A-Za-z0-9._-]+)==(\S+)", open(sys.argv[1]).read(), re.M)
print("\n".join(sorted(name.lower().replace("_", "-") + "==" + version for name, version in pins)))
PY
)"
installed="$(docker run --rm -i --entrypoint /opt/venv/bin/python "$AI_IMAGE" - <<'PY'
import importlib.metadata as metadata
names = (d.metadata["Name"].lower().replace("_", "-") + "==" + d.version for d in metadata.distributions())
print("\n".join(sorted(n for n in names if not n.startswith("pip=="))))
PY
)"
check "gateway packages are exactly services/ai/requirements.lock" test "$locked" = "$installed"
check "no compiler in the gateway image (wheels only)" in_ai '! command -v gcc && ! command -v cc'
check "gateway has no .env, tests or dev requirements" in_ai 'test ! -e /srv/ai/.env && test ! -e /srv/ai/tests && test ! -e /srv/ai/requirements-dev.txt && test -z "$(find /srv -name ".env*")"'

set +e
docker run --rm -e SERVICE_TOKEN= "$AI_IMAGE" >/dev/null 2>&1; no_token=$?
docker run --rm -e SERVICE_TOKEN=dev-local-only-token "$AI_IMAGE" >/dev/null 2>&1; dev_token=$?
set -e
check "gateway refuses to start without a service token" test "$no_token" != "0"
check "gateway refuses the development token in production" test "$dev_token" != "0"

docker run -d --name "${RUN_ID}-ai" --network "$NETWORK" -e SERVICE_TOKEN="$(random)" "$AI_IMAGE" >/dev/null
ai_get() { docker exec "${RUN_ID}-ai" python -c 'import sys,urllib.request,urllib.error
try:
    r=urllib.request.urlopen("http://127.0.0.1:8100"+sys.argv[1]); print(r.status, r.headers.get("server"), r.read().decode())
except urllib.error.HTTPError as e:
    print(e.code, e.headers.get("server"), e.read().decode())' "$1"; }
for _ in $(seq 1 20); do ai_get /health/live >/dev/null 2>&1 && break; sleep 1; done
check "gateway liveness 200" grep -q '^200' <<<"$(ai_get /health/live 2>/dev/null || true)"
check "gateway readiness 200 with a token" grep -q '^200' <<<"$(ai_get /health/ready 2>/dev/null || true)"
check "gateway does not name its server" grep -q '^200 None' <<<"$(ai_get /health/live 2>/dev/null || true)"
gateway_logs="$(docker logs "${RUN_ID}-ai" 2>&1 || true)"
check "gateway logs are structured JSON" grep -q '"service":"ai-gateway","process_role":"gateway","environment":"production"' <<<"$gateway_logs"
check "gateway access lines carry no client address" test -z "$(grep '"event_code":"http.access"' <<<"$gateway_logs" | grep -E '"client|127\.0\.0\.1:' || true)"
check "no external model provider enabled" docker exec "${RUN_ID}-ai" python -c 'from app.core.config import settings; import sys; sys.exit(1 if settings.real_providers_allowed else 0)'

echo
if [[ "$failures" -gt 0 ]]; then
    echo "verify-images: ${failures} check(s) FAILED"
    exit 1
fi
echo "verify-images: all checks passed (local images only; nothing deployed)"
