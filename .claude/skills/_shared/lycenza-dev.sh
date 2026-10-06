# Shared helpers for the Lycenza project skills (.claude/skills/*/run.sh).
# Sourced, never executed. Development tooling only: nothing here deploys,
# pushes or touches a non-test database.
#
# The isolated Compose project naming and the test-environment values below
# MIRROR apps/platform/bin/safe-test (rule 78) -- that script stays the
# canonical runner. Every script that runs artisan against the database first
# calls `platform:env-diagnostic`, which refuses unless the resolved
# configuration matches the fail-closed testing contract, so a drift here
# fails closed instead of reaching a non-test database (rules 50-54).

lyc_repo_root() {
  git rev-parse --show-toplevel
}

# The isolated project safe-test derives for a worktree root (SAFE_TEST_ISOLATED=1).
lyc_project() {
  local root="$1"
  printf 'school-os-test-%s' "$(printf '%s' "${root}" | cksum | cut -d' ' -f1)"
}

lyc_platform_container() { printf '%s-platform-1' "$(lyc_project "$1")"; }
lyc_postgres_container() { printf '%s-postgres-1' "$(lyc_project "$1")"; }

# Inert local test values (CLAUDE.md "Running things locally"; phpunit.xml).
LYC_SAFE_ENV=(
  -e "APP_ENV=testing"
  -e "DB_HOST=postgres"
  -e "DB_PORT=5432"
  -e "DB_DATABASE=school_os_test"
  -e "DB_USERNAME=school_os_app"
  -e "DB_PASSWORD=school_os_app_local_only_password"
  -e "DB_ADMIN_USERNAME=school_os"
  -e "DB_ADMIN_PASSWORD=school_os"
  -e "DB_RETENTION_USERNAME=school_os_retention"
  -e "DB_RETENTION_PASSWORD=school_os_retention_local_only_password"
  -e "DB_RETENTION_URL="
  -e "CACHE_STORE=array"
  -e "SESSION_DRIVER=array"
  -e "QUEUE_CONNECTION=sync"
  -e "MAIL_MAILER=array"
)

# Starts (or reuses) this worktree's isolated project and verifies the
# testing contract, via safe-test itself.
lyc_ensure_isolated() {
  local root="$1" output
  if ! output="$(SAFE_TEST_ISOLATED=1 "${root}/apps/platform/bin/safe-test" --diagnostic 2>&1)"; then
    printf '%s\n' "${output}" >&2
    return 1
  fi
}

lyc_artisan() {
  local root="$1"; shift
  docker exec "${LYC_SAFE_ENV[@]}" "$(lyc_platform_container "${root}")" php artisan "$@"
}

# A git tree id for the CURRENT working tree: tracked changes plus untracked,
# non-ignored files, computed in a throwaway index (the real index is never
# touched). Two identical candidate trees always give the same id, so it is
# the fingerprint tying a regression run to what is later published.
lyc_candidate_tree() {
  local root="$1" tmp
  tmp="$(mktemp)"
  cp "$(git -C "${root}" rev-parse --path-format=absolute --git-path index)" "${tmp}"
  GIT_INDEX_FILE="${tmp}" git -C "${root}" add -A >/dev/null
  GIT_INDEX_FILE="${tmp}" git -C "${root}" write-tree
  rm -f "${tmp}"
}

# Files the candidate adds, changes or renames relative to HEAD.
lyc_changed_files() {
  local root="$1" tmp
  tmp="$(mktemp)"
  cp "$(git -C "${root}" rev-parse --path-format=absolute --git-path index)" "${tmp}"
  GIT_INDEX_FILE="${tmp}" git -C "${root}" add -A >/dev/null
  GIT_INDEX_FILE="${tmp}" git -C "${root}" diff --cached --name-only --diff-filter=ACMR HEAD
  rm -f "${tmp}"
}

lyc_strip_ansi() { sed 's/\x1b\[[0-9;]*m//g'; }
