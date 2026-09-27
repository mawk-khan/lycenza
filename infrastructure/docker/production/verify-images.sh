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
#                  (Phase 0O.6B) every required PHP extension loads; no build
#                  toolchain/perl/curl/xz in the application runtime; CA
#                  bundles, TLS-capable libpq and PHP timezone data present.
#   custom PHP     -- (Phase 0O.6D) PHP 8.3.35 built from source; the exact
#                  expected extension set; libcurl 8.22.0 / libxml2 2.15.4
#                  resolved from /usr/local/lib by php and php-fpm (never a
#                  Debian copy -- none is installed); ELF package notes; no
#                  headers, source, phpize or PEAR; SIGQUIT stop signal; and a
#                  native smoke INSIDE the image (runtime-checks/
#                  php-native-smoke.php): the webhook HTTP client stack over
#                  real TLS with a throwaway CA, AWS SDK against a real MinIO,
#                  every PHP XML API -- repeated, crash signatures refused.
#   runtime security -- (Phase 0O.6F) EVERY container of both images is started
#                  with the runtime security contract's reference invocation
#                  (infrastructure/release/runtime-security.json: all
#                  capabilities dropped, none added, no-new-privileges, never
#                  privileged); the kernel's own /proc/<pid>/status proves uid,
#                  capability sets and NoNewPrivs for every process of every
#                  role; mount/umount carry no setuid bit; a setgid program
#                  gains nothing (with a control run proving the test
#                  discriminates); /etc/fstab has no user-mountable entry.
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

# Phase 0O.6F: the production runtime security contract's reference invocation.
mapfile -t HARDEN < <(python3 -c 'import json,sys; print("\n".join(json.load(open(sys.argv[1]))["reference_invocation"]["docker"]))' "$ROOT/infrastructure/release/runtime-security.json")
drun() { docker run "${HARDEN[@]}" "$@"; }

pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; failures=$((failures + 1)); }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then pass "$name"; else fail "$name"; fi; }

SMOKE_DIR="$(mktemp -d)"
cleanup() {
    docker ps -aq --filter "name=${RUN_ID}" | xargs -r docker rm -f >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    rm -rf "$SMOKE_DIR"
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

# Phase 0O.7A (ADR 0053): ephemeral NON-PRODUCTION Ed25519 service keys for this
# run only -- generated inside the app image by libsodium, held in memory,
# passed to the throwaway containers' environment, never written anywhere.
service_keypair() { # kid -> "<private JWK><TAB><public JWK>"
    drun --rm --entrypoint php "$APP_IMAGE" -r '$seed = random_bytes(32); $pair = sodium_crypto_sign_seed_keypair($seed);
        $b = fn ($x) => rtrim(strtr(base64_encode($x), "+/", "-_"), "=");
        $public = ["kty" => "OKP", "crv" => "Ed25519", "kid" => $argv[1], "x" => $b(sodium_crypto_sign_publickey($pair)), "created" => gmdate("Y-m-d")];
        $private = ["kty" => "OKP", "crv" => "Ed25519", "kid" => $argv[1], "x" => $public["x"], "d" => $b($seed), "created" => $public["created"]];
        echo json_encode($private), "\t", json_encode($public), "\n";' "$1"
}
IFS=$'\t' read -r PLATFORM_KEY PLATFORM_PUBLIC < <(service_keypair verify-platform-1)
IFS=$'\t' read -r GATEWAY_KEY GATEWAY_PUBLIC < <(service_keypair verify-ai-gateway-1)
IFS=$'\t' read -r DEV_GATEWAY_KEY _ < <(service_keypair dev-local-only-ai-gateway-9)
PLATFORM_SEED="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["d"])' "$PLATFORM_KEY")"
# The committed development private seeds (never allowed in an image).
DEV_SEEDS=()
while IFS= read -r seed; do DEV_SEEDS+=(-e "$seed"); done < <(python3 -c 'import json,re,sys
for path in sys.argv[1:]:
    for m in re.finditer(r"\{[^{}]*\"d\":\"[A-Za-z0-9_-]{43}\"[^{}]*\}", open(path).read()):
        print(json.loads(m.group(0))["d"])' "$ROOT/apps/platform/.env.example" "$ROOT/services/ai/.env.example")
check "the two committed development private seeds were found (for the image checks)" test "${#DEV_SEEDS[@]}" = 4
no_dev_seed() { # image path... : true when none of the committed dev seeds appears
    local image=$1
    shift
    ! drun --rm --entrypoint grep "$image" -rqsF "${DEV_SEEDS[@]}" "$@"
}

APP_ENV_ARGS=(
    -e METRICS_SCRAPE_TOKEN="$METRICS_TOKEN"
    -e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    -e APP_URL=https://verify.invalid
    -e APP_DEBUG=false
    -e SESSION_SECURE_COOKIE=true
    -e AI_GATEWAY_CONTEXT_SIGNING_KEY="$(random)"
    -e AI_GATEWAY_BASE_URL=https://ai-gateway.invalid
    -e AI_GATEWAY_SERVICE_SIGNING_KEY="$PLATFORM_KEY"
    -e AI_GATEWAY_INBOUND_VERIFICATION_KEYS="[$GATEWAY_PUBLIC]"
    -e AI_GATEWAY_REPLAY_STORE=redis
    -e DB_HOST=192.0.2.10 -e DB_SSLMODE=require
    -e DB_USERNAME=school_os_app -e DB_PASSWORD="$(random)"
    -e REDIS_HOST="${RUN_ID}-redis" -e REDIS_PASSWORD="$REDIS_PASSWORD"
    -e CACHE_STORE=redis -e SESSION_DRIVER=redis -e QUEUE_CONNECTION=redis
    -e DOCUMENTS_DISK=s3 -e COMMUNICATION_ATTACHMENTS_DISK=s3
    -e AWS_BUCKET=lycenza-verify -e AWS_DEFAULT_REGION=us-east-1 -e AWS_ENDPOINT=https://objects.invalid
    -e TRUSTED_PROXIES=10.0.0.0/8
    -e INTERNAL_HOSTS=platform-internal.verify.invalid
)

in_app() { drun --rm --entrypoint sh "$APP_IMAGE" -c "$1"; }

echo "== app image: $APP_IMAGE"
check "app runs as a non-root user" test "$(in_app 'id -u')" != "0"
check "no .env file anywhere in the application" test -z "$(in_app 'find /var/www/app -name ".env*" -not -path "*/vendor/*"')"
check "no tests or PHPUnit configuration" in_app 'test ! -e /var/www/app/tests && test ! -e /var/www/app/phpunit.xml'
check "no dev Composer packages" in_app 'test ! -e /var/www/app/vendor/phpunit && test ! -e /var/www/app/vendor/mockery && test ! -e /var/www/app/vendor/larastan'
check "no node_modules" in_app 'test ! -e /var/www/app/node_modules'
check "no development private service key in the app image (ADR 0053)" no_dev_seed "$APP_IMAGE" /var/www/app /usr/local /etc
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
# Phase 0O.6B: the base-image change (Debian 13) must not lose a PHP extension
# or native dependency, TLS trust, or timezone support -- and the build
# toolchain the PHP base image carries is purged from the runtime.
check "every required PHP extension loads" in_app 'for m in bcmath ctype curl dom fileinfo gd iconv json mbstring openssl pcntl pdo_pgsql pgsql session sodium tokenizer xml zip zlib; do php -r "exit(extension_loaded(\"$m\") ? 0 : 1);" || exit 1; done && php -m | grep -qx "Zend OPcache"'
check "no compiler, build toolchain, perl (beyond Essential perl-base), curl or xz in the application runtime" in_app '! command -v gcc && ! command -v cc && ! command -v make && ! command -v curl && ! command -v xz && for p in perl libc6-dev dpkg-dev binutils; do ! dpkg -s "$p" >/dev/null 2>&1 || exit 1; done'
check "CA bundle present and PHP/OpenSSL verify against it" in_app 'test -s /etc/ssl/certs/ca-certificates.crt && php -r "exit(openssl_x509_parse(file_get_contents(\"/etc/ssl/certs/ca-certificates.crt\")) ? 0 : 1);"'
check "PostgreSQL client library is TLS-capable" in_app 'ldd /usr/lib/x86_64-linux-gnu/libpq.so.5 | grep -q libssl'
check "PHP timezone database resolves School timezones" in_app 'php -r "new DateTimeZone(\"Asia/Kolkata\"); new DateTimeZone(\"America/New_York\");"'
# Phase 0O.6D: the repository-built PHP runtime and its patched libraries.
expected_modules="$(tr '\n' ' ' < "$ROOT/infrastructure/docker/production/php-modules.expected")"
check "PHP is the repository-built 8.3.35" in_app 'php -r "exit(PHP_VERSION === \"8.3.35\" ? 0 : 1);"'
check "PHP module set equals php-modules.expected exactly" test "$(in_app 'php -m | grep -v "^\[" | grep -v "^$" | sort -u | tr "\n" " "')" = "$expected_modules"
check "libcurl 8.22.0 and libxml2 2.15.4 are the loaded runtime versions" in_app 'php -r "exit(curl_version()[\"version\"] === \"8.22.0\" && LIBXML_DOTTED_VERSION === \"2.15.4\" ? 0 : 1);"'
check "php and php-fpm resolve libcurl.so.4 and libxml2.so.16 from /usr/local/lib" in_app 'for b in /usr/local/bin/php /usr/local/sbin/php-fpm; do ldd "$b" | grep -q "libcurl.so.4 => /usr/local/lib/libcurl.so.4" && ldd "$b" | grep -q "libxml2.so.16 => /usr/local/lib/libxml2.so.16" || exit 1; done'
check "no binary or extension has an unresolved library" test -z "$(in_app 'ldd /usr/local/bin/php /usr/local/sbin/php-fpm /usr/local/lib/php/extensions/*/*.so | grep "not found"')"
check "the loader registers /usr/local/lib explicitly" in_app 'grep -qx /usr/local/lib /etc/ld.so.conf.d/00-lycenza-native.conf && ldconfig -p | grep -q "libcurl.so.4 .*=> /usr/local/lib/libcurl.so.4"'
check "no Debian libcurl/libxml2 package and no other copy on disk" in_app '! dpkg -s libcurl4t64 >/dev/null 2>&1 && ! dpkg -s libxml2 >/dev/null 2>&1 && test "$(find / -xdev \( -name "libcurl.so*" -o -name "libxml2.so*" \) 2>/dev/null | sort | tr "\n" " ")" = "/usr/local/lib/libcurl.so.4 /usr/local/lib/libxml2.so.16 "'
check "patched libraries carry their ELF package notes" in_app 'grep -aq "\"name\":\"curl\",\"version\":\"8.22.0\"" /usr/local/lib/libcurl.so.4 && grep -aq "\"name\":\"libxml2\",\"version\":\"2.15.4\"" /usr/local/lib/libxml2.so.16'
check "no headers, PHP/curl/libxml2 source, phpize, php-config or PEAR" in_app 'test -z "$(find /usr/local/include /usr/src -mindepth 1 2>/dev/null)" && ! command -v phpize && ! command -v php-config && ! command -v pear && ! command -v curl-config && ! command -v xmllint'
check "graceful FPM stop signal is SIGQUIT" test "$(docker image inspect -f '{{.Config.StopSignal}}' "$APP_IMAGE")" = "SIGQUIT"
check "no private-key file in application or configuration paths" test -z "$(in_app 'find /var/www/app /usr/local/etc /etc/nginx \( -name "*.pem" -o -name "*.key" -o -name "id_rsa*" \) -not -path "*/vendor/*" 2>/dev/null')"

docker network create "$NETWORK" >/dev/null
docker run -d --name "${RUN_ID}-redis" --network "$NETWORK" redis:7-alpine redis-server --requirepass "$REDIS_PASSWORD" --save '' --appendonly no >/dev/null

unsafe_output="$(drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e APP_KEY=canary-unsafe-app-key "$APP_IMAGE" web 2>&1 || true)"
check "unsafe configuration refuses to start" grep -q "Refusing to start" <<<"$unsafe_output"
check "refusal never prints the value" test -z "$(grep -F canary-unsafe-app-key <<<"$unsafe_output")"
legacy_output="$(drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e AI_GATEWAY_SERVICE_TOKEN=canary-legacy-shared-token "$APP_IMAGE" web 2>&1 || true)"
check "app refuses the retired shared service token (ADR 0053)" grep -q "ai_legacy_service_token_configured" <<<"$legacy_output"
check "that refusal never prints the token or a key" test -z "$(grep -F -e canary-legacy-shared-token -e "$PLATFORM_SEED" <<<"$legacy_output")"

drun -d --name "${RUN_ID}-web" --network "$NETWORK" "${APP_ENV_ARGS[@]}" "$APP_IMAGE" web >/dev/null
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
drun -d --name "${RUN_ID}-web-proxied" --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e TRUSTED_PROXIES=127.0.0.1/32 "$APP_IMAGE" web >/dev/null
proxied() { docker exec "${RUN_ID}-web-proxied" php -r '$c=stream_context_create(["http"=>["ignore_errors"=>true,"header"=>"X-Forwarded-Proto: https"]]); @file_get_contents("http://127.0.0.1:8080/api/health/live",false,$c); echo implode("\n",$http_response_header ?? []);'; }
for _ in $(seq 1 30); do proxied 2>/dev/null | grep -q '200' && break; sleep 1; done
check "HSTS through a trusted proxy" grep -qi '^strict-transport-security: max-age=31536000$' <<<"$(proxied 2>/dev/null | tr -d '\r' || true)"

# Phase 0O.8A (ADR 0054): the Host boundary and custom-domain safety in the image.
misdirected="$(http /login 'Host: attacker.invalid' 2>/dev/null || true)"
check "an unknown Host is refused with 421 before any cookie (ADR 0054)" grep -q "HTTP/1.1 421" <<<"$misdirected"
check "that refusal sets no cookie" test -z "$(grep -i '^set-cookie' <<<"$misdirected" || true)"
check "the probe dot-path reaches the application's Host boundary, not nginx's dotfile deny" grep -q "HTTP/1.1 421" <<<"$(http '/.well-known/lycenza-domain-probe?n=x' 'Host: attacker.invalid' 2>/dev/null || true)"
check "internal AI routes are absent on the platform host (404)" grep -q "HTTP/1.1 404" <<<"$(http /api/internal/ai/completions/authorize 'Host: verify.invalid' 2>/dev/null || true)"
check "the internal host serves no browser page (404)" grep -q "HTTP/1.1 404" <<<"$(http /login 'Host: platform-internal.verify.invalid' 2>/dev/null || true)"
check "health answers on an IP Host, never on an unknown name" bash -c 'grep -q "HTTP/1.1 200" <<<"$1" && grep -q "HTTP/1.1 404" <<<"$2"' _ "$live" "$(http /api/health/live 'Host: school.attacker.invalid' 2>/dev/null || true)"
HANDOFF_CANARY="verifyhandoffcanary$(random)"
http "/session/handoff?ticket=${HANDOFF_CANARY}" 'Host: attacker.invalid' >/dev/null 2>&1 || true
sleep 1
web_access="$(docker logs "${RUN_ID}-web" 2>&1 || true)"
check "the access log records paths, never query strings (a handoff ticket never reaches it)" bash -c 'grep -q "/session/handoff" <<<"$1" && ! grep -qF "$2" <<<"$1"' _ "$web_access" "$HANDOFF_CANARY"
for unsafe in DOMAIN_FAKES_ENABLED=true:domain_fakes_enabled DOMAIN_ALLOW_DEVELOPMENT_HOSTS=true:domain_development_hosts_enabled SESSION_DOMAIN=.verify.invalid:session_domain_shared INTERNAL_HOSTS=web:domain_host_list_invalid; do
    refusal="$(drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e "${unsafe%%:*}" "$APP_IMAGE" web 2>&1 || true)"
    check "production refuses ${unsafe%%=*} (${unsafe##*:})" grep -q "${unsafe##*:}" <<<"$refusal"
done
PROBE_CANARY="dev-local-only-domain-probe-key-change-me-0000"
enabled_refusal="$(drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e CUSTOM_DOMAINS_ENABLED=true -e DOMAIN_PROBE_KEY="$PROBE_CANARY" "$APP_IMAGE" web 2>&1 || true)"
check "enabled custom domains need an edge target, a real probe key and public resolvers" bash -c 'for code in domain_edge_target_missing domain_probe_key_invalid domain_dns_resolvers_missing; do grep -q "$code" <<<"$1" || exit 1; done' _ "$enabled_refusal"
check "that refusal never prints the probe key" test -z "$(grep -F "$PROBE_CANARY" <<<"$enabled_refusal" || true)"
check "the pinned Public Suffix List works without intl (ASCII-only v1)" drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" --entrypoint php "$APP_IMAGE" -r '
    require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $psl = app(App\Support\Domains\PublicSuffixPolicy::class); $psl->assertRegistrable("erp.northfield.co.uk");
    try { $psl->assertRegistrable("co.uk"); exit(1); } catch (App\Support\Domains\HostnameRejected $e) {}
    exit(extension_loaded("intl") ? 1 : 0);'
check "the DNS client loads without ext-sockets (unconfigured is indeterminate, never absent)" drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" --entrypoint php "$APP_IMAGE" -r '
    require "vendor/autoload.php";
    exit((new App\Support\Domains\Dns\NetDns2DomainResolver([]))->txt("_lycenza-verification.example.org")->isIndeterminate() ? 0 : 1);'

# Phase 0O.5A (ADR 0051): structured logs and the private metrics listener.
log_line="$(drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" -e PROCESS_ROLE=console --entrypoint php "$APP_IMAGE" -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\Log::info("verify.images.structured", ["password" => "canary-image-pw"]);' 2>&1 | grep '"event_code":"verify.images.structured"' || true)"
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

routes="$(drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" "$APP_IMAGE" console route:list --json 2>/dev/null || true)"
check "production route list builds" grep -q '"uri"' <<<"$routes"
# The one production probe route is ADR 0054's public TLS readiness probe.
check "no probe or demo route in production (only the ADR 0054 domain probe)" test -z "$(grep -oE '"uri":"[^"]*(probe|demo)[^"]*"' <<<"$routes" | grep -vxF '"uri":".well-known\/lycenza-domain-probe"')"
check "configuration and routes cache in production mode" drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" --entrypoint sh "$APP_IMAGE" -c 'php artisan config:cache && php artisan route:cache && php artisan route:list >/dev/null'

# --- Phase 0O.6F: runtime security contract, proven from the kernel --------------
# One /proc/<pid>/status record per line-separated paragraph on stdin: every
# record must be non-root, hold no permitted/effective/bounding/ambient
# capability, and have NoNewPrivs=1.
statuses_ok() {
    awk -v RS= '
        { n++; uid = eff = prm = bnd = amb = nnp = ""
          c = split($0, line, "\n")
          for (i = 1; i <= c; i++) { split(line[i], f, "\t")
              if (f[1] == "Uid:") uid = f[2]; if (f[1] == "CapEff:") eff = f[2]; if (f[1] == "CapPrm:") prm = f[2]
              if (f[1] == "CapBnd:") bnd = f[2]; if (f[1] == "CapAmb:") amb = f[2]; if (f[1] == "NoNewPrivs:") nnp = f[2] }
          z = "0000000000000000"
          if (uid == "0" || uid == "" || eff != z || prm != z || bnd != z || (amb != "" && amb != z) || nnp != "1") bad = 1 }
        END { exit (bad || n == 0) }'
}
proc_statuses() { docker exec "$1" sh -c 'for s in /proc/[0-9]*/status; do cat "$s" 2>/dev/null; echo; done'; }
# The worker and scheduler roles exit a few seconds after start in this
# harness (the database is deliberately unreachable), so their process
# evidence is captured as soon as the role's PHP process is running.
declare -A ROLE_STATUS=()
role_status() { # container
    local i out
    for i in $(seq 1 40); do
        out="$(proc_statuses "$1" 2>/dev/null)" || return 1
        if grep -qE '^Name:[[:space:]]+php$' <<<"$out"; then printf '%s\n' "$out"; return 0; fi
        sleep 0.25
    done
    return 1
}

for role in "worker default" "worker integrations" "worker notifications" scheduler; do
    name="${RUN_ID}-$(tr ' ' '-' <<<"$role")"
    # shellcheck disable=SC2086
    drun -d --name "$name" --network "$NETWORK" "${APP_ENV_ARGS[@]}" "$APP_IMAGE" $role >/dev/null
done
# (captured in the background so the "stays running" timing below is unchanged)
for role in worker-default worker-integrations worker-notifications scheduler; do
    role_status "${RUN_ID}-$role" >"$SMOKE_DIR/status-$role" 2>/dev/null &
done
sleep 5
for role in worker-default worker-integrations worker-notifications scheduler; do
    check "$role role stays running" test "$(docker inspect -f '{{.State.Running}}' "${RUN_ID}-${role}")" = "true"
done
wait
for role in worker-default worker-integrations worker-notifications scheduler; do
    ROLE_STATUS[${RUN_ID}-$role]="$(cat "$SMOKE_DIR/status-$role")"
done
# --- Phase 0O.6F: runtime security contract (continued) -------------------------
every_process_ok() { # container: live, or the status captured at role start
    if [[ -v "ROLE_STATUS[$1]" ]]; then statuses_ok <<<"${ROLE_STATUS[$1]}"; else proc_statuses "$1" | statuses_ok; fi
}
self_ok() { # image expected-uid
    test "$(docker run "${HARDEN[@]}" --rm --entrypoint id "$1" -u)" = "$2" \
        && docker run "${HARDEN[@]}" --rm --entrypoint cat "$1" /proc/self/status | statuses_ok
}
invocation_ok() {
    test "$(docker inspect -f '{{.HostConfig.Privileged}}|{{json .HostConfig.CapDrop}}|{{json .HostConfig.CapAdd}}|{{json .HostConfig.SecurityOpt}}' "$1")" = 'false|["ALL"]|null|["no-new-privileges:true"]'
}
fstab_ok() { # image
    ! docker run "${HARDEN[@]}" --rm --entrypoint cat "$1" /etc/fstab | awk '!/^[[:space:]]*(#|$)/' \
        | grep -qE '(^|[[:space:],])(user|users|owner|group|bind|rbind|X-mount\.[A-Za-z]+)([=,[:space:]]|$)'
}
app_roles_ok() { local c; for c in web worker-default worker-integrations worker-notifications scheduler; do "$1" "${RUN_ID}-$c" || return 1; done; }
setuid_removed='test "$(stat -c %a /usr/bin/mount)" = 755 && test "$(stat -c %a /usr/bin/umount)" = 755 && grep -qx "root root 755 /usr/bin/mount" /var/lib/dpkg/statoverride && grep -qx "root root 755 /usr/bin/umount" /var/lib/dpkg/statoverride'

check "app: hardened invocation -- not privileged, all capabilities dropped, none added, no-new-privileges" app_roles_ok invocation_ok
check "app: runs as www-data (uid 33) with no permitted, effective or bounding capability and NoNewPrivs=1" self_ok "$APP_IMAGE" 33
check "app: every process of the web, worker and scheduler roles is non-root with no capability and NoNewPrivs=1" app_roles_ok every_process_ok
check "app: mount/umount carry no setuid bit (dpkg-statoverride)" in_app "$setuid_removed"
check "app: setuid/setgid execution gains no privilege under the hardened invocation" in_app '! chage -l www-data >/dev/null 2>&1 && ! mount -t tmpfs none /tmp >/dev/null 2>&1'
check "control: without no-new-privileges the same setgid program does gain privilege (the test discriminates)" docker run --rm --entrypoint chage "$APP_IMAGE" -l www-data
check "app: /etc/fstab has no user-mountable entry" fstab_ok "$APP_IMAGE"

set +e; drun --rm "$APP_IMAGE" bogus >/dev/null 2>&1; unknown=$?; set -e
check "unknown role refused (exit 64)" test "$unknown" = "64"

# --- Phase 0O.6D native smoke inside the production image ------------------------
# Throwaway CA + server certificate (SANs: the pinned test name only), an
# HTTPS test server and a MinIO, both on the private network; nothing leaves
# this run.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=lycenza-verify-ca" -keyout "$SMOKE_DIR/ca.key" -out "$SMOKE_DIR/ca.pem" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj "/CN=pinned.invalid" -keyout "$SMOKE_DIR/server.key" -out "$SMOKE_DIR/server.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:pinned.invalid\n' > "$SMOKE_DIR/san.ext"
openssl x509 -req -in "$SMOKE_DIR/server.csr" -CA "$SMOKE_DIR/ca.pem" -CAkey "$SMOKE_DIR/ca.key" -CAcreateserial -days 1 -extfile "$SMOKE_DIR/san.ext" -out "$SMOKE_DIR/server.pem" >/dev/null 2>&1
cat > "$SMOKE_DIR/server.py" <<'PY'
import http.server, json, ssl, time
class H(http.server.BaseHTTPRequestHandler):
    def _reply(self):
        if self.path.startswith("/slow"):
            time.sleep(10)
        if self.path.startswith("/redirect"):
            self.send_response(302); self.send_header("Location", "/ok"); self.send_header("Content-Length", "0"); self.end_headers(); return
        body = json.dumps({"ok": True}).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self): self._reply()
    def do_POST(self):
        self.rfile.read(int(self.headers.get("Content-Length", 0))); self._reply()
    def log_message(self, *args): pass
server = http.server.ThreadingHTTPServer(("0.0.0.0", 8443), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain("/tls/server.pem", "/tls/server.key")
server.socket = ctx.wrap_socket(server.socket, server_side=True)
server.serve_forever()
PY
chmod a+r "$SMOKE_DIR"/*
docker run -d --name "${RUN_ID}-tls" --network "$NETWORK" -v "$SMOKE_DIR:/tls:ro" \
    python:3.14.7-slim-trixie@sha256:51dafde81dbdb6ebde285137a295cf18a47ca95234fe388a343719cb97305b3d python /tls/server.py >/dev/null
MINIO_KEY="verify$(random | cut -c1-12)"; MINIO_SECRET="$(random)"
docker run -d --name "${RUN_ID}-minio" --network "$NETWORK" -e MINIO_ROOT_USER="$MINIO_KEY" -e MINIO_ROOT_PASSWORD="$MINIO_SECRET" \
    minio/minio:RELEASE.2025-04-08T15-41-24Z server /data >/dev/null
tls_ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "${RUN_ID}-tls")"
for _ in $(seq 1 30); do docker exec "${RUN_ID}-minio" sh -c 'exec 3<>/dev/tcp/127.0.0.1/9000' 2>/dev/null && break; sleep 1; done
smoke() { drun --rm --network "$NETWORK" -v "$ROOT/infrastructure/docker/production/runtime-checks:/checks:ro" -v "$SMOKE_DIR/ca.pem:/checks-ca/ca.pem:ro" \
    -e TLS_HOST="${RUN_ID}-tls" -e TLS_IP="$tls_ip" -e TLS_CA=/checks-ca/ca.pem -e S3_ENDPOINT="http://${RUN_ID}-minio:9000" \
    -e S3_KEY="$MINIO_KEY" -e S3_SECRET="$MINIO_SECRET" -e ROUNDS="$1" --entrypoint php "$APP_IMAGE" /checks/php-native-smoke.php 2>&1; }
set +e; smoke_out="$(smoke 3)"; smoke_rc=$?; smoke_again="$(smoke 1)"; again_rc=$?; set -e
printf '%s\n' "$smoke_out" | sed 's/^/    /'
check "native smoke passes inside the production image (3 rounds)" test "$smoke_rc" = "0"
check "native smoke passes again in a fresh process" test "$again_rc" = "0"
check "no crash signature (segfault/abort/symbol/loader error)" test -z "$(printf '%s\n%s\n' "$smoke_out" "$smoke_again" | grep -iE 'segmentation|core dumped|abort|symbol lookup|error while loading shared' || true)"
check "no crash exit status (139 SIGSEGV / 134 SIGABRT)" test "$smoke_rc" != "139" -a "$smoke_rc" != "134" -a "$again_rc" != "139" -a "$again_rc" != "134"

echo "== gateway image: $AI_IMAGE"
in_ai() { drun --rm --entrypoint sh "$AI_IMAGE" -c "$1"; }
check "gateway runs as a non-root user" test "$(in_ai 'id -u')" != "0"
locked="$(python3 - "$ROOT/services/ai/requirements.lock" <<'PY'
import re, sys
pins = re.findall(r"^([A-Za-z0-9._-]+)==(\S+)", open(sys.argv[1]).read(), re.M)
print("\n".join(sorted(name.lower().replace("_", "-") + "==" + version for name, version in pins)))
PY
)"
installed="$(drun --rm -i --entrypoint /opt/venv/bin/python "$AI_IMAGE" - <<'PY'
import importlib.metadata as metadata
names = (d.metadata["Name"].lower().replace("_", "-") + "==" + d.version for d in metadata.distributions())
print("\n".join(sorted(n for n in names if not n.startswith("pip=="))))
PY
)"
check "gateway packages are exactly services/ai/requirements.lock" test "$locked" = "$installed"
check "no compiler in the gateway image (wheels only)" in_ai '! command -v gcc && ! command -v cc'
check "gateway runs CPython 3.14 (Phase 0O.6C, CVE-2026-82049)" in_ai 'python -c "import sys; sys.exit(0 if sys.version_info[:2] == (3, 14) else 1)"'
check "gateway TLS trust store is populated" in_ai 'python -c "import ssl,sys; sys.exit(0 if ssl.create_default_context().cert_store_stats()[\"x509_ca\"] > 0 else 1)"'
check "gateway has no .env, tests or dev requirements" in_ai 'test ! -e /srv/ai/.env && test ! -e /srv/ai/tests && test ! -e /srv/ai/requirements-dev.txt && test -z "$(find /srv -name ".env*")"'

# Phase 0O.7A (ADR 0053): the Gateway's own `ai-gateway` key and the ring of
# `platform` public keys; ENVIRONMENT defaults to production.
GATEWAY_ENV=(
    -e SERVICE_SIGNING_KEY="$GATEWAY_KEY"
    -e PLATFORM_VERIFICATION_KEYS="[$PLATFORM_PUBLIC]"
    -e ERP_CONTRACT_BASE_URL="https://platform.invalid/api/internal/ai"
)
check "gateway has the audited Ed25519 library (cryptography, ADR 0053)" in_ai 'python -c "from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey; Ed25519PrivateKey.generate()"'
check "no development private service key in the gateway image (ADR 0053)" no_dev_seed "$AI_IMAGE" /srv /opt/venv /etc

set +e
drun --rm "$AI_IMAGE" >/dev/null 2>&1; no_keys=$?
drun --rm "${GATEWAY_ENV[@]}" -e SERVICE_TOKEN=canary-legacy-shared-token "$AI_IMAGE" >/dev/null 2>&1; legacy_token=$?
drun --rm "${GATEWAY_ENV[@]}" -e SERVICE_SIGNING_KEY="$DEV_GATEWAY_KEY" "$AI_IMAGE" >/dev/null 2>&1; dev_key=$?
drun --rm "${GATEWAY_ENV[@]}" -e ERP_CONTRACT_BASE_URL=http://platform.invalid/api/internal/ai "$AI_IMAGE" >/dev/null 2>&1; plaintext=$?
set -e
check "gateway refuses to start without service keys" test "$no_keys" != "0"
check "gateway refuses the retired SERVICE_TOKEN (any value)" test "$legacy_token" != "0"
check "gateway refuses a development service key in production" test "$dev_key" != "0"
check "gateway refuses a plaintext Laravel URL in production" test "$plaintext" != "0"

drun -d --name "${RUN_ID}-ai" --network "$NETWORK" "${GATEWAY_ENV[@]}" "$AI_IMAGE" >/dev/null
ai_get() { docker exec "${RUN_ID}-ai" python -c 'import sys,urllib.request,urllib.error
try:
    r=urllib.request.urlopen("http://127.0.0.1:8100"+sys.argv[1]); print(r.status, r.headers.get("server"), r.read().decode())
except urllib.error.HTTPError as e:
    print(e.code, e.headers.get("server"), e.read().decode())' "$1"; }
for _ in $(seq 1 20); do ai_get /health/live >/dev/null 2>&1 && break; sleep 1; done
check "gateway liveness 200" grep -q '^200' <<<"$(ai_get /health/live 2>/dev/null || true)"
check "gateway: hardened invocation -- not privileged, all capabilities dropped, none added, no-new-privileges" invocation_ok "${RUN_ID}-ai"
check "gateway: runs as gateway (uid 10001) with no permitted, effective or bounding capability and NoNewPrivs=1" self_ok "$AI_IMAGE" 10001
check "gateway: every process is non-root with no capability and NoNewPrivs=1" every_process_ok "${RUN_ID}-ai"
check "gateway: mount/umount carry no setuid bit (dpkg-statoverride)" in_ai "$setuid_removed"
check "gateway: setuid/setgid execution gains no privilege under the hardened invocation" in_ai '! chage -l gateway >/dev/null 2>&1 && ! mount -t tmpfs none /tmp >/dev/null 2>&1'
check "gateway: /etc/fstab has no user-mountable entry" fstab_ok "$AI_IMAGE"
check "gateway readiness 200 with valid service keys" grep -q '^200' <<<"$(ai_get /health/ready 2>/dev/null || true)"
check "gateway does not name its server" grep -q '^200 None' <<<"$(ai_get /health/live 2>/dev/null || true)"
gateway_logs="$(docker logs "${RUN_ID}-ai" 2>&1 || true)"
check "gateway logs are structured JSON" grep -q '"service":"ai-gateway","process_role":"gateway","environment":"production"' <<<"$gateway_logs"
check "gateway access lines carry no client address" test -z "$(grep '"event_code":"http.access"' <<<"$gateway_logs" | grep -E '"client|127\.0\.0\.1:' || true)"
check "no external model provider enabled" docker exec "${RUN_ID}-ai" python -c 'from app.core.config import settings; import sys; sys.exit(1 if settings.real_providers_allowed else 0)'

# --- Phase 0O.7A (ADR 0053): both signed directions, real containers -------------
# Laravel -> Gateway: the app image's own signer (`platform` key) calls the
# running Gateway. An unknown agent proves authentication passed (403 from
# the agent check, not 401); nothing else authenticates; a replay to the same
# Gateway process is refused.
platform_call() { # mode: signed | bearer | replay
    drun --rm --network "$NETWORK" "${APP_ENV_ARGS[@]}" --entrypoint php "$APP_IMAGE" -r '
        require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $url = "http://".$argv[1].":8100/v1/complete";
        $body = json_encode(["agent" => "verify-no-such-agent", "context_token" => "t", "prompt" => "p"]);
        $auth = $argv[2] === "bearer" ? "Bearer verify-not-a-credential" : app(App\Support\ServiceAuth\ServiceAuthKeys::class)->signer()->authorization("POST", $url, $body);
        $send = function () use ($url, $body, $auth) {
            $c = stream_context_create(["http" => ["method" => "POST", "ignore_errors" => true, "timeout" => 20, "header" => "Content-Type: application/json\r\nAuthorization: {$auth}\r\n", "content" => $body]]);
            $r = file_get_contents($url, false, $c);
            return explode(" ", $http_response_header[0])[1]." ".$r;
        };
        $first = $send();
        echo $argv[2] === "replay" ? $send() : $first;' "${RUN_ID}-ai" "$1"
}
check "platform -> gateway: a signed platform assertion authenticates (the unknown agent is then refused)" grep -q '^403 {"detail":"agent_not_allowed"}' <<<"$(platform_call signed 2>&1 || true)"
check "platform -> gateway: any other credential gets the uniform 401" grep -q '^401 {"error":{"code":"service_authentication_failed"}}' <<<"$(platform_call bearer 2>&1 || true)"
check "platform -> gateway: a replay to the same Gateway process is refused" grep -q '^401 {"error":{"code":"service_authentication_failed"}}' <<<"$(platform_call replay 2>&1 || true)"

# Gateway -> Laravel: the Gateway image's own signer (`ai-gateway` key) calls a
# hardened web role. PostgreSQL is deliberately unreachable here, so this web
# instance keeps its maintenance flag in Redis (still a shared store); a
# rejected context token proves authentication passed (context_invalid, not
# service_authentication_failed); a replay is refused through Redis.
# ADR 0054 section 8.3: the internal AI routes answer only on the configured
# internal host, so the Gateway calls this web role by that private name.
drun -d --name "${RUN_ID}-web-internal" --network "$NETWORK" --network-alias platform-internal.verify.invalid "${APP_ENV_ARGS[@]}" -e APP_MAINTENANCE_STORE=redis "$APP_IMAGE" web >/dev/null
for _ in $(seq 1 30); do docker exec "${RUN_ID}-web-internal" php -r 'exit(@file_get_contents("http://127.0.0.1:8080/api/health/live") === false ? 1 : 0);' 2>/dev/null && break; sleep 1; done
gateway_call() { # mode: signed | bearer | replay | wrong-audience
    drun --rm --network "$NETWORK" "${GATEWAY_ENV[@]}" --entrypoint /opt/venv/bin/python "$AI_IMAGE" -c '
import json, sys, urllib.error, urllib.request
from app.core.security import service_auth
from app.core.service_auth import AI_GATEWAY, AUDIENCE_AI_GATEWAY, ServiceSigner, parse_signing_key
from app.core.config import settings
url = "http://" + sys.argv[1] + ":8080/api/internal/ai/completions/authorize"
body = json.dumps({"context_token": "not.a-real-token", "capability": "school.settings.view"}).encode()
mode = sys.argv[2]
if mode == "bearer":
    auth = "Bearer verify-not-a-credential"
elif mode == "wrong-audience":
    auth = ServiceSigner(parse_signing_key(settings.service_signing_key), issuer=AI_GATEWAY, audience=AUDIENCE_AI_GATEWAY).authorization("POST", url, body)
else:
    auth = service_auth.signer.authorization("POST", url, body)
def send():
    request = urllib.request.Request(url, data=body, method="POST", headers={"Content-Type": "application/json", "Accept": "application/json", "Authorization": auth})
    try:
        response = urllib.request.urlopen(request, timeout=20)
        return f"{response.status} {response.read().decode()}"
    except urllib.error.HTTPError as e:
        return f"{e.code} {e.read().decode()}"
first = send()
print(send() if mode == "replay" else first)' platform-internal.verify.invalid "$1"
}
check "gateway -> platform: a signed ai-gateway assertion authenticates (the context check then decides)" grep -q '^401 {"error":{"code":"context_invalid"}}' <<<"$(gateway_call signed 2>&1 || true)"
check "gateway -> platform: any other credential gets the uniform 401" grep -q '^401 {"error":{"code":"service_authentication_failed"}}' <<<"$(gateway_call bearer 2>&1 || true)"
check "gateway -> platform: an assertion for the Gateway audience is refused" grep -q '^401 {"error":{"code":"service_authentication_failed"}}' <<<"$(gateway_call wrong-audience 2>&1 || true)"
check "gateway -> platform: a replayed assertion is refused (one-time jti in Redis)" grep -q '^401 {"error":{"code":"service_authentication_failed"}}' <<<"$(gateway_call replay 2>&1 || true)"
check "gateway -> platform: the web role logs a closed code and never the assertion" test -n "$(docker logs "${RUN_ID}-web-internal" 2>&1 | grep '"event_code":"service_auth.failed"' | grep '"outcome":"replayed"' | grep -v 'eyJ' || true)"

echo
if [[ "$failures" -gt 0 ]]; then
    echo "verify-images: ${failures} check(s) FAILED"
    exit 1
fi
echo "verify-images: all checks passed (local images only; nothing deployed)"
