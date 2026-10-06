#!/usr/bin/env bash
#
# Full canonical regression of the CURRENT candidate tree, run from a
# throwaway git worktree snapshot so the main working tree stays free to edit
# for the whole (~40 min) run. See SKILL.md.
#
#   .claude/skills/full-regression/run.sh            # full suite
#   .claude/skills/full-regression/run.sh --filter=X # forwarded to safe-test (recorded as partial)
#
# Result: .claude/regression/last-result (key=value) and
# .claude/regression/<tree>.log. Exit status = the suite's.

set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../_shared/lycenza-dev.sh"

root="$(lyc_repo_root)"
out="${root}/.claude/regression"
snap="${out}/snapshot"
mkdir -p "${out}"

tree="$(lyc_candidate_tree "${root}")"
base="$(git -C "${root}" rev-parse HEAD)"
commit="$(git -C "${root}" commit-tree "${tree}" -p "${base}" -m "regression snapshot of ${tree}")"

# One stable snapshot path, so its isolated Compose project (named from the
# path, as safe-test does) is reused run after run instead of multiplying.
#
# The test containers run as root, so the suite leaves root-owned files in
# apps/platform/storage (e.g. storage/framework/testing/disks) and
# bootstrap/cache. release_root_owned() hands ONLY those generated paths back to
# the invoking user (never vendor or source), through a container, so the
# snapshot can be removed without sudo.
owner="$(id -u):$(id -g)"
release_root_owned() {
  local dir="$1" image
  [ -d "${dir}/apps/platform" ] || return 0
  image="$(docker inspect -f '{{.Config.Image}}' "$(lyc_platform_container "${root}")" 2>/dev/null || echo 'school-os-platform:latest')"
  docker run --rm -u root -v "${dir}/apps/platform:/x" --entrypoint sh "${image}" \
    -c "for p in /x/storage /x/bootstrap/cache; do [ -e \"\$p\" ] && chown -R ${owner} \"\$p\"; done; true" >/dev/null 2>&1 || true
}
if git -C "${root}" worktree list --porcelain | grep -qx "worktree ${snap}"; then
  release_root_owned "${snap}"
  git -C "${root}" worktree remove --force "${snap}"
fi
if [ -e "${snap}" ]; then
  release_root_owned "${snap}"
  rm -rf "${snap}"
fi
git -C "${root}" worktree prune
git -C "${root}" worktree add --detach "${snap}" "${commit}" >/dev/null

# Always remove the snapshot worktree (its hardlinked vendor with it --
# originals untouched), however the run ends; the isolated Compose project
# stays for reuse.
#
# The snapshot project's containers bind-mount files from the snapshot
# directory (platform: apps/platform; postgres: its init scripts), which is
# recreated every run, so containers left from a previous run would point at a
# deleted directory. They are removed before the run (safe-test recreates them
# against the fresh snapshot) and again afterwards -- ONLY containers labelled
# with this snapshot's own Compose project (rule 81); its named volumes are
# kept, so the database persists and --reset-db re-seeds it.
project="$(lyc_project "${snap}")"
drop_containers() {
  docker ps -aq --filter "label=com.docker.compose.project=${project}" | xargs -r docker rm -f >/dev/null 2>&1 || true
}
cleanup() {
  docker exec -u root "$(lyc_platform_container "${snap}")" sh -c "for p in /var/www/app/storage /var/www/app/bootstrap/cache; do [ -e \"\$p\" ] && chown -R ${owner} \"\$p\"; done; true" >/dev/null 2>&1 || true
  drop_containers
  release_root_owned "${snap}"
  git -C "${root}" worktree remove --force "${snap}" >/dev/null 2>&1 || true
  [ -e "${snap}" ] && rm -rf "${snap}" 2>/dev/null || true
}
trap cleanup EXIT
drop_containers

# Gitignored runtime inputs the containers need: vendor (hardlinks -- instant,
# no extra disk, the originals are never modified) and every local env file
# docker-compose.yml's env_file entries name (safe-test overrides every
# safety-relevant value anyway).
cp -al "${root}/apps/platform/vendor" "${snap}/apps/platform/vendor"
# The built Vite assets: every Inertia page test renders app.blade.php, which
# needs public/build/manifest.json (gitignored). Same assets the main tree's
# own runs use; rebuild in the main tree first (npm run build) when the
# candidate changes resources/js.
if [ -d "${root}/apps/platform/public/build" ]; then
  cp -al "${root}/apps/platform/public/build" "${snap}/apps/platform/public/build"
fi
while IFS= read -r envfile; do
  if [ -f "${root}/${envfile}" ]; then cp "${root}/${envfile}" "${snap}/${envfile}"; fi
done < <(grep -oE '^[[:space:]]+- \./[^[:space:]]+' "${root}/docker-compose.yml" | sed 's#.*- \./##' | grep -E '(^|/)\.env$' | sort -u)

log="${out}/${tree}.log"
echo "[full-regression] candidate tree ${tree} (base ${base})"
echo "[full-regression] snapshot ${snap}; isolated project $(lyc_project "${snap}")"
echo "[full-regression] log ${log}"

start="$(date +%s)"
set +e
(
  SAFE_TEST_ISOLATED=1 "${snap}/apps/platform/bin/safe-test" --reset-db &&
  SAFE_TEST_ISOLATED=1 "${snap}/apps/platform/bin/safe-test" "$@"
) >"${log}" 2>&1
status=$?
set -e
end="$(date +%s)"

summary="$(lyc_strip_ansi <"${log}" | grep -E '^(OK|Tests:|FAILURES!|ERRORS!)' | tr '\n' ' ' | sed 's/ *$//' || true)"
[ -n "${summary}" ] || summary="no PHPUnit summary -- the run stopped early; see the log"
scope="full"; [ "$#" -gt 0 ] && scope="partial: $*"

cat >"${out}/last-result" <<EOF
tree=${tree}
base=${base}
scope=${scope}
status=${status}
duration_seconds=$((end - start))
summary=${summary}
log=${log}
finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF

echo "[full-regression] status=${status} scope=${scope} duration=$((end - start))s"
echo "[full-regression] ${summary}"
exit "${status}"
