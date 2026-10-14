"""The one central path classifier (ADR 0072 sections 6-7, 12; E03.1).

Every changed path (both sides of a rename, deleted paths included) maps to a
path CLASS by the first matching rule below, and each class requires a set of
LANES. A candidate requires the union. Fail closed:

- a path no rule names is `unknown`, which requires every lane (STRICT);
- the CI definition and this tooling are `ci`, which also requires every lane,
  so a workflow change can never classify itself as docs-only (section 22);
- no diff at all, or no usable base, requires every lane.

The `secrets`, `supply_chain` and `integrity` lanes are required for every
candidate. A lane not required is legitimately skipped, and the `CI gate`
accepts exactly that skip and nothing else (gate.py).
"""

from __future__ import annotations

import fnmatch
from dataclasses import dataclass
from datetime import UTC, datetime

LANES: tuple[str, ...] = (
    "secrets",
    "supply_chain",
    "integrity",
    "images",
    "platform",
    "docs",
    "gateway",
    "shared_types",
    "mobile",
)
ALWAYS: frozenset[str] = frozenset({"secrets", "supply_chain", "integrity"})
ALL: frozenset[str] = frozenset(LANES)
# The strictest requirement: every lane except `docs`, whose PHPUnit guards
# the full suite (`platform`) already runs.
STRICT: frozenset[str] = ALL - {"docs"}

# Ordered: the FIRST matching pattern decides a path's class. Patterns use
# fnmatch, where `*` also crosses `/`, so `apps/platform/*` is the whole tree.
RULES: tuple[tuple[str, str], ...] = (
    # The CI definition, this tooling and the stack CI runs: strictest.
    (".github/*", "ci"),
    ("infrastructure/ci/*", "ci"),
    ("docker-compose.yml", "ci"),
    (".gitleaks.toml", "ci"),
    # Application code, its frontend and its tests (the full suite).
    ("apps/platform/*", "platform"),
    ("apps/mobile/*", "mobile"),
    # The Gateway; PHPUnit guards also read services/ai files.
    ("services/ai/*", "gateway"),
    # Contracts feed the generated shared types and the PHP OpenAPI tests.
    ("packages/contracts/*", "contracts"),
    ("packages/shared-types/*", "shared_types"),
    # Image and release inputs; PHPUnit guards read many of these files.
    ("infrastructure/docker/*", "images"),
    ("infrastructure/postgres/*", "images"),
    ("infrastructure/release/*", "release"),
    # Any other infrastructure (terraform, empty by design) is strict on purpose.
    ("infrastructure/*", "infrastructure"),
    (".dockerignore", "images"),
    # Documentation: docs/** and Markdown at the repository root.
    ("docs/*", "docs"),
    ("README.md", "docs"),
    ("CLAUDE.md", "docs"),
    # Developer/agent tooling that neither CI nor the application executes.
    (".claude/*", "dev_tooling"),
    (".ddev/*", "dev_tooling"),
    ("docker-compose.phase*.yml", "dev_tooling"),
    (".gitignore", "dev_tooling"),
)

CLASS_LANES: dict[str, frozenset[str]] = {
    "ci": ALL,
    "unknown": ALL,
    "infrastructure": ALL,
    "platform": frozenset({"platform", "images"}),
    "mobile": frozenset({"mobile"}),
    "gateway": frozenset({"gateway", "images", "platform"}),
    "contracts": frozenset({"shared_types", "platform"}),
    "shared_types": frozenset({"shared_types"}),
    "images": frozenset({"images", "platform"}),
    "release": frozenset({"images", "platform"}),
    "docs": frozenset({"docs"}),
    "dev_tooling": frozenset(),
}


def path_class(path: str) -> str:
    for pattern, klass in RULES:
        if fnmatch.fnmatchcase(path, pattern):
            return klass
    return "unknown"


@dataclass(frozen=True)
class Classification:
    lanes: frozenset[str]
    strict: bool
    reason: str
    classes: dict[str, str]

    def ordered_lanes(self) -> list[str]:
        return [lane for lane in LANES if lane in self.lanes]


def classify(paths: list[str] | None) -> Classification:
    """`paths` is None when no usable base exists; [] when the diff is empty."""
    if paths is None:
        return Classification(STRICT, True, "no usable base commit: every lane", {})
    if not paths:
        return Classification(STRICT, True, "empty diff: every lane", {})
    classes = {path: path_class(path) for path in sorted(set(paths))}
    lanes: set[str] = set(ALWAYS)
    for klass in classes.values():
        lanes |= CLASS_LANES[klass]
    # The full suite already runs every documentation guard; the docs lane
    # exists for candidates that do not run it.
    if "platform" in lanes:
        lanes.discard("docs")
    strict = any(CLASS_LANES[klass] == ALL for klass in classes.values())
    reason = "strict path (ci/infrastructure/unknown): every lane" if strict else "path classes: " + ", ".join(sorted(set(classes.values())))
    return Classification(frozenset(lanes), strict, reason, classes)


def parse_name_status(output: str) -> list[str]:
    """Paths from `git diff --name-status -z -M`: both sides of a rename/copy."""
    fields = output.split("\0")
    paths: list[str] = []
    index = 0
    while index < len(fields):
        status = fields[index]
        if not status:
            index += 1
            continue
        if status[0] in ("R", "C"):
            paths.extend(fields[index + 1:index + 3])
            index += 3
        else:
            paths.append(fields[index + 1])
            index += 2
    return [path for path in paths if path]


# --- CI sharding Option A (ADR 0072 section 31): no duplicate heavy lanes on main --------

INTEGRATION_PREFIX = "integration/"


def prior_integration_validation(sha: str, check_runs: list[dict], suites: dict[int, dict], app_id: int, gate: str) -> str | None:
    """Was this exact SHA already validated by its integration candidate run?

    `main` only ever moves by fast-forward to a SHA whose `CI gate` from
    GitHub Actions is green (ruleset 24909660, ADR 0072 section 29), so a push
    to `main` re-runs nothing heavy when the NEWEST completed Actions `CI gate`
    run for exactly this SHA from an `integration/**` check suite succeeded.
    Returns a description of that run, or None -- and None (anything missing,
    foreign, unfinished or red) means the ordinary path classification.
    """
    def stamp(run: dict) -> datetime:
        value = run.get("completed_at") or ""
        try:
            return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(UTC)
        except ValueError:
            return datetime.min.replace(tzinfo=UTC)

    candidates = []
    for run in check_runs:
        suite = suites.get((run.get("check_suite") or {}).get("id"))
        if (run.get("name") != gate or run.get("head_sha") != sha or (run.get("app") or {}).get("id") != app_id
                or run.get("status") != "completed" or not suite):
            continue
        if (suite.get("app") or {}).get("id") != app_id or suite.get("head_sha") != sha:
            continue
        if not str(suite.get("head_branch") or "").startswith(INTEGRATION_PREFIX):
            continue
        candidates.append((stamp(run), run, suite))
    if not candidates:
        return None
    _, run, suite = max(candidates, key=lambda item: item[0])
    if run.get("conclusion") != "success":
        return None
    return f"check run {run.get('id')} on {suite.get('head_branch')} ({run.get('completed_at')})"


def already_validated(description: str) -> Classification:
    return Classification(ALWAYS, False, f"exact SHA already validated by its integration candidate ({description}): always lanes only", {})
