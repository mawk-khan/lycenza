#!/usr/bin/env bash
#
# Pre-publication gate for the CURRENT candidate tree. It never commits or
# pushes. Its only possible write: when packages/contracts changed, it
# regenerates packages/shared-types in place, so a drift is left in the tree
# for review. See SKILL.md.
#
#   .claude/skills/publish-gate/run.sh                       # all gates; regression evidence reported
#   .claude/skills/publish-gate/run.sh --require-regression  # also FAIL unless the last green full
#                                                            # regression ran on exactly this tree
#
# Gates: Pint, Larastan (scratch tmpDir), whitespace (git diff --check over the
# whole candidate, untracked files included), gitleaks on the changed files
# (pinned image, repository allowlist, mounted at /repo so its path allowlists
# match), shared-types drift when packages/contracts changed, and the
# regression evidence for this tree.

set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../_shared/lycenza-dev.sh"

require_regression=0
[ "${1:-}" = "--require-regression" ] && require_regression=1

root="$(lyc_repo_root)"
failed=0
pass() { printf '  PASS  %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failed=1; }
note() { printf '  NOTE  %s\n' "$1"; }

GITLEAKS_IMAGE='zricethezav/gitleaks:v8.28.0@sha256:cdbb7c955abce02001a9f6c9f602fb195b7fadc1e812065883f695d1eeaba854'

tree="$(lyc_candidate_tree "${root}")"
mapfile -t changed < <(lyc_changed_files "${root}")
echo "[publish-gate] candidate tree ${tree}; ${#changed[@]} changed file(s) vs HEAD"

if ! lyc_ensure_isolated "${root}"; then
  fail "isolated test environment (bin/safe-test --diagnostic)"
  exit 1
fi
container="$(lyc_platform_container "${root}")"

if docker exec "${container}" vendor/bin/pint --test >/dev/null 2>&1; then pass "Pint"; else fail "Pint (run: docker exec ${container} vendor/bin/pint --test)"; fi

if docker exec "${container}" sh -c 'printf "includes:\n    - /var/www/app/phpstan.neon\nparameters:\n    tmpDir: /tmp/phpstan-publish-gate\n" > /tmp/publish-gate-phpstan.neon && php -d memory_limit=2G vendor/bin/phpstan analyse -c /tmp/publish-gate-phpstan.neon --no-progress --memory-limit=2G' >/dev/null 2>&1; then
  pass "Larastan"
else
  fail "Larastan"
fi

tmp_index="$(mktemp)"
cp "$(git -C "${root}" rev-parse --path-format=absolute --git-path index)" "${tmp_index}"
GIT_INDEX_FILE="${tmp_index}" git -C "${root}" add -A >/dev/null
if GIT_INDEX_FILE="${tmp_index}" git -C "${root}" diff --cached --check HEAD >/dev/null; then pass "whitespace (git diff --check, untracked included)"; else fail "whitespace (git diff --check)"; fi
rm -f "${tmp_index}"

if [ "${#changed[@]}" -eq 0 ]; then
  note "gitleaks skipped: no changed files"
else
  scan="$(mktemp -d)"
  for f in "${changed[@]}"; do
    mkdir -p "${scan}/$(dirname "${f}")"
    cp "${root}/${f}" "${scan}/${f}"
  done
  cp "${root}/.gitleaks.toml" "${scan}/.gitleaks.toml"
  if docker run --rm -v "${scan}:/repo:ro" "${GITLEAKS_IMAGE}" dir /repo --config /repo/.gitleaks.toml --no-banner --redact --exit-code 1 >/dev/null 2>&1; then
    pass "gitleaks (changed files)"
  else
    fail "gitleaks (changed files) -- rerun without >/dev/null to see the redacted findings"
  fi
  rm -rf "${scan}"
fi

if printf '%s\n' "${changed[@]}" | grep -q '^packages/contracts/'; then
  if (cd "${root}/packages/shared-types" && npm run -s generate >/dev/null 2>&1 && git -C "${root}" diff --quiet -- packages/shared-types/src/generated && npm run -s type-check >/dev/null 2>&1); then
    pass "shared-types regenerate (no drift) + type-check"
  else
    fail "shared-types drift or type-check (packages/contracts changed)"
  fi
fi

result="${root}/.claude/regression/last-result"
if [ -f "${result}" ] && grep -qx "tree=${tree}" "${result}" && grep -qx "status=0" "${result}" && grep -qx "scope=full" "${result}"; then
  pass "full regression green on exactly this tree ($(grep '^summary=' "${result}" | cut -d= -f2-))"
elif [ "${require_regression}" -eq 1 ]; then
  fail "no green full regression for this tree (run .claude/skills/full-regression/run.sh)"
else
  note "no green full regression recorded for this tree (rule 82 cadence decides whether one is due)"
fi

if [ "${failed}" -eq 0 ]; then echo "[publish-gate] PASS"; else echo "[publish-gate] FAIL"; fi
exit "${failed}"
