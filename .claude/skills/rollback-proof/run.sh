#!/usr/bin/env bash
#
# Migration rollback proof (CLAUDE.md rule 10) on THIS worktree's isolated
# TEST database only. See SKILL.md.
#
#   .claude/skills/rollback-proof/run.sh <steps>
#
# Rolls back the last <steps> migrations on pgsql_admin, re-applies them, and
# requires the schema-only dump after re-apply to be byte-identical to the one
# before. Then runs platform:verify-database. Refuses unless the resolved
# configuration passes platform:env-diagnostic AND the admin connection's
# current_database() is exactly school_os_test (rules 50-54).

set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../_shared/lycenza-dev.sh"

steps="${1:-}"
if ! [[ "${steps}" =~ ^[1-9][0-9]*$ ]]; then
  echo "usage: $0 <steps>  (the number of most recent migrations to roll back and re-apply)" >&2
  exit 2
fi

root="$(lyc_repo_root)"
lyc_ensure_isolated "${root}"
pg="$(lyc_postgres_container "${root}")"

lyc_artisan "${root}" platform:env-diagnostic >/dev/null
db="$(docker exec "${pg}" psql -U school_os -d school_os_test -Atc 'select current_database()')"
if [ "${db}" != "school_os_test" ]; then
  echo "[rollback-proof] refusing: resolved database is '${db}', not school_os_test" >&2
  exit 1
fi

work="$(mktemp -d)"
dump() { docker exec "${pg}" pg_dump -U school_os -d school_os_test --schema-only 2>/dev/null | grep -v '^\\\(un\)\?restrict'; }

echo "[rollback-proof] the ${steps} migration(s) to prove:"
lyc_artisan "${root}" migrate:status --database=pgsql_admin | lyc_strip_ansi | grep -E 'Ran|Pending' | tail -n "${steps}"

dump >"${work}/before.sql"
lyc_artisan "${root}" migrate:rollback --step="${steps}" --database=pgsql_admin --force | lyc_strip_ansi | grep -E 'DONE|FAIL|ERROR' || true
dump >"${work}/rolled-back.sql"
lyc_artisan "${root}" migrate --database=pgsql_admin --force | lyc_strip_ansi | grep -E 'DONE|FAIL|ERROR' || true
dump >"${work}/after.sql"

removed="$(diff "${work}/before.sql" "${work}/rolled-back.sql" | grep -c '^<' || true)"
if diff -q "${work}/before.sql" "${work}/after.sql" >/dev/null; then
  echo "[rollback-proof] IDENTICAL schema after rollback + re-apply (${removed} schema lines removed by the rollback)"
  rc=0
else
  echo "[rollback-proof] DIFFERENT schema after rollback + re-apply -- see ${work}/before.sql vs ${work}/after.sql" >&2
  rc=1
fi

verify="$(lyc_artisan "${root}" platform:verify-database 2>&1 | lyc_strip_ansi | tail -1)"
echo "[rollback-proof] platform:verify-database: ${verify}"
case "${verify}" in failed=0*) ;; *) rc=1 ;; esac

[ "${rc}" -eq 0 ] && rm -rf "${work}"
echo "[rollback-proof] the test database is migrated; the next regression resets it anyway (bin/safe-test --reset-db)"
exit "${rc}"
