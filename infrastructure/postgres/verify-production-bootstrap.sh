#!/usr/bin/env bash
#
# Phase 0O.4A (ADR 0050 section 7): proves production-bootstrap.sql on a
# CLEAN, THROWAWAY PostgreSQL 16 cluster -- nothing shared, nothing real:
#
#   1. starts postgres:16 with TLS (a throwaway self-signed certificate) and
#      a migration role deliberately NOT named `school_os`;
#   2. runs the bootstrap twice (idempotency) and checks its refusals
#      (wrong database, runtime role as migration role);
#   3. sets a throwaway runtime password out of band (\password equivalent);
#   4. migrates and seeds through the PRODUCTION application image, as
#      production would (APP_ENV=production, DB_SSLMODE=require, admin
#      credentials only on the release step);
#   5. runs `platform:verify-database` on the runtime connection, and the
#      bootstrap a third time on the migrated schema (no grant on existing
#      tables: DELETE revocations survive);
#   6. removes everything.
#
# Usage (repository root):  infrastructure/postgres/verify-production-bootstrap.sh
#   APP_IMAGE=lycenza-app:<tag> (default lycenza-app:verify; build it with
#   infrastructure/docker/production/verify-images.sh --build)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_IMAGE="${APP_IMAGE:-lycenza-app:verify}"
RUN_ID="lycenza-pgverify-$$"
NETWORK="${RUN_ID}-net"
DB="${RUN_ID}-db"
CERTS="$(mktemp -d)"
MIGRATION_ROLE=lycenza_owner
DATABASE=lycenza
random() { head -c 32 /dev/urandom | base64 | tr -d '/+=' | cut -c1-32; }
OWNER_PASSWORD="$(random)"
APP_PASSWORD="$(random)"
failures=0

pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; failures=$((failures + 1)); }

cleanup() {
    docker rm -f "$DB" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    rm -rf "$CERTS"
}
trap cleanup EXIT

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=${DB}" \
    -keyout "$CERTS/server.key" -out "$CERTS/server.crt" >/dev/null 2>&1

docker network create "$NETWORK" >/dev/null
docker run -d --name "$DB" --network "$NETWORK" \
    -e POSTGRES_USER="$MIGRATION_ROLE" -e POSTGRES_PASSWORD="$OWNER_PASSWORD" -e POSTGRES_DB="$DATABASE" \
    -v "$CERTS:/certs:ro" --entrypoint bash postgres:16 -c '
        install -o postgres -m 0600 /certs/server.key /var/lib/postgresql/server.key &&
        install -o postgres -m 0644 /certs/server.crt /var/lib/postgresql/server.crt &&
        exec docker-entrypoint.sh postgres -c ssl=on \
            -c ssl_cert_file=/var/lib/postgresql/server.crt -c ssl_key_file=/var/lib/postgresql/server.key' >/dev/null

for _ in $(seq 1 60); do docker exec "$DB" pg_isready -U "$MIGRATION_ROLE" -d "$DATABASE" >/dev/null 2>&1 && break; sleep 1; done
sleep 2 # the entrypoint restarts the server once after initdb
for _ in $(seq 1 30); do docker exec "$DB" pg_isready -U "$MIGRATION_ROLE" -d "$DATABASE" >/dev/null 2>&1 && break; sleep 1; done

docker cp "$ROOT/infrastructure/postgres/production-bootstrap.sql" "$DB:/tmp/bootstrap.sql" >/dev/null
bootstrap() { docker exec "$DB" psql -X -q -U "$MIGRATION_ROLE" -d "${3:-$DATABASE}" -v ON_ERROR_STOP=1 -v migration_role="$1" -v app_database="$2" -f /tmp/bootstrap.sql; }
psql_q() { docker exec "$DB" psql -X -At -U "$MIGRATION_ROLE" -d "$DATABASE" -c "$1"; }

if bootstrap school_os_app "$DATABASE" >/dev/null 2>&1; then fail "refuses school_os_app as the migration role"; else pass "refuses school_os_app as the migration role"; fi
if bootstrap "$MIGRATION_ROLE" some_other_database >/dev/null 2>&1; then fail "refuses a database mismatch"; else pass "refuses a database mismatch"; fi
if bootstrap missing_role "$DATABASE" >/dev/null 2>&1; then fail "refuses a missing migration role"; else pass "refuses a missing migration role"; fi
if bootstrap "$MIGRATION_ROLE" "$DATABASE" >/dev/null && bootstrap "$MIGRATION_ROLE" "$DATABASE" >/dev/null; then pass "bootstrap runs twice (idempotent)"; else fail "bootstrap runs twice (idempotent)"; fi

attrs="$(psql_q "select rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication, rolpassword is null from pg_authid where rolname = 'school_os_app'")"
[[ "$attrs" == "t|f|f|f|f|f|f|t" ]] && pass "runtime role attributes, no password set by the script" || fail "runtime role attributes ($attrs)"
[[ "$(psql_q "select pg_has_role('school_os_app', '$MIGRATION_ROLE', 'MEMBER')")" == "f" ]] && pass "runtime role not a member of the migration role" || fail "runtime role membership"

# A pre-existing role with a dangerous attribute is refused, never "fixed".
psql_q "alter role school_os_app createdb" >/dev/null
if bootstrap "$MIGRATION_ROLE" "$DATABASE" >/dev/null 2>&1; then fail "refuses an existing runtime role with unexpected attributes"; else pass "refuses an existing runtime role with unexpected attributes"; fi
psql_q "alter role school_os_app nocreatedb" >/dev/null

# E21-RH.2 (ADR 0066): the dedicated retention identity -- same narrow attributes, no password, no
# membership in the migration or runtime role, no default privileges; a dangerous attribute is refused.
attrs="$(psql_q "select rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication, rolpassword is null from pg_authid where rolname = 'school_os_retention'")"
[[ "$attrs" == "t|f|f|f|f|f|f|t" ]] && pass "retention role attributes, no password set by the script" || fail "retention role attributes ($attrs)"
[[ "$(psql_q "select pg_has_role('school_os_retention', '$MIGRATION_ROLE', 'MEMBER') or pg_has_role('school_os_retention', 'school_os_app', 'MEMBER')")" == "f" ]] && pass "retention role not a member of the migration or runtime role" || fail "retention role membership"
[[ "$(psql_q "select count(*) from pg_default_acl where defaclacl::text like '%school_os_retention=%'")" == "0" ]] && pass "retention role has no default privileges" || fail "retention role default privileges"
psql_q "alter role school_os_retention bypassrls" >/dev/null
if bootstrap "$MIGRATION_ROLE" "$DATABASE" >/dev/null 2>&1; then fail "refuses an existing retention role with unexpected attributes"; else pass "refuses an existing retention role with unexpected attributes"; fi
psql_q "alter role school_os_retention nobypassrls" >/dev/null

# Out of band, as an operator would with \password (never a file or argv in production).
psql_q "alter role school_os_app password '${APP_PASSWORD}'" >/dev/null

ENV_ARGS=(
    -e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" -e APP_URL=https://verify.invalid
    -e SESSION_SECURE_COOKIE=true -e AI_GATEWAY_CONTEXT_SIGNING_KEY="$(random)"
    -e METRICS_SCRAPE_TOKEN="$(random)$(random)"
    -e DB_HOST="$DB" -e DB_DATABASE="$DATABASE" -e DB_SSLMODE=require
    -e DB_USERNAME=school_os_app -e DB_PASSWORD="$APP_PASSWORD"
    -e REDIS_PASSWORD="$(random)" -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync
    -e DOCUMENTS_DISK=s3 -e COMMUNICATION_ATTACHMENTS_DISK=s3
    -e AWS_BUCKET=lycenza-verify -e AWS_DEFAULT_REGION=us-east-1 -e AWS_ENDPOINT=https://objects.invalid
)
ADMIN_ARGS=(-e DB_ADMIN_USERNAME="$MIGRATION_ROLE" -e DB_ADMIN_PASSWORD="$OWNER_PASSWORD")
console() { docker run --rm --network "$NETWORK" "${ENV_ARGS[@]}" "$@"; }

if console "${ADMIN_ARGS[@]}" "$APP_IMAGE" console migrate --database=pgsql_admin --force >/dev/null; then pass "migrations run as the migration role through the production image"; else fail "migrations"; fi
if console "${ADMIN_ARGS[@]}" "$APP_IMAGE" console db:seed --force >/dev/null; then pass "production-safe seeders (runtime connection, PRODUCTION-RELEASE step 7)"; else fail "production-safe seeders"; fi


verify="$(console "$APP_IMAGE" console platform:verify-database 2>&1)" && pass "platform:verify-database passes (runtime role, read-only)" || { fail "platform:verify-database"; echo "$verify"; }
grep -q "FAIL" <<<"$verify" && fail "verify-database reported a FAIL" || true
grep -qE "PASS +\| runtime_connection_encrypted" <<<"$verify" && pass "runtime connection encrypted (TLS, server-confirmed)" || fail "runtime connection encrypted"

before="$(psql_q "select count(*) from information_schema.table_privileges where grantee = 'school_os_app' and privilege_type = 'DELETE'")"
bootstrap "$MIGRATION_ROLE" "$DATABASE" >/dev/null && pass "bootstrap re-runs on a migrated schema" || fail "bootstrap re-run on a migrated schema"
after="$(psql_q "select count(*) from information_schema.table_privileges where grantee = 'school_os_app' and privilege_type = 'DELETE'")"
[[ "$before" == "$after" ]] && pass "re-run grants nothing on existing tables (DELETE revocations intact)" || fail "re-run changed DELETE grants ($before -> $after)"

console "$APP_IMAGE" console platform:verify-database >/dev/null 2>&1 && pass "verify-database still passes after re-run" || fail "verify-database after re-run"

echo
if [[ "$failures" -gt 0 ]]; then echo "verify-production-bootstrap: ${failures} check(s) FAILED"; exit 1; fi
echo "verify-production-bootstrap: all checks passed (throwaway cluster, removed on exit)"
