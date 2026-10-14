"""Deterministic PHPUnit sharding of the canonical suite (ADR 0072 section 31; CI sharding Option A).

The owner-selected Option A (2026-10-13) keeps the FULL canonical suite on
every code-lane candidate and only changes how it executes: in N parallel
shards. Sharding never selects tests -- the inventory is whatever the
canonical `phpunit.xml` would run (`phpunit --list-tests-xml`), and every
test FILE of that inventory lands in exactly one shard.

- **Unit of partition:** a test file (one test class per file; a file is
  never split, so a class's setUp/data providers stay together).
- **Algorithm:** longest-processing-time first by test-case count (data-set
  cases included): files sorted by (count desc, path asc), each assigned to
  the shard with the smallest (load, index). Deterministic: the same
  inventory always gives the same membership; no timing data, no randomness.
- **Execution order inside a shard:** the canonical inventory order.
- **Proof:** `plan()` refuses an incomplete or overlapping partition;
  `verify()` checks one shard's JUnit per-class counts equal its plan
  exactly; `coverage()` checks, across every shard report of a run, that all
  shards planned from the same inventory, that the shard indices are exactly
  1..N, and that the union of what ran is the whole inventory -- once each.
"""

from __future__ import annotations

import hashlib
import json
import re
import xml.etree.ElementTree as ET
from collections import Counter
from dataclasses import dataclass
from pathlib import Path

from . import junit

MAX_SHARDS = 32
CONTAINER_ROOT = "/var/www/app"
SHARD_CONFIG = "phpunit.shard.xml"
TESTSUITES = re.compile(r"\n?([ \t]*)<testsuites>.*?</testsuites>", re.DOTALL)


class ShardError(ValueError):
    """The inventory, a plan or a shard report is not acceptable (fail closed)."""


@dataclass(frozen=True)
class TestFile:
    path: str          # relative to apps/platform, e.g. tests/Feature/X/YTest.php
    classes: tuple[str, ...]
    tests: int
    order: int         # position in the canonical inventory


def parse_shard(value: str) -> tuple[int, int]:
    """`X/Y` -> (X, Y); 1 <= X <= Y <= MAX_SHARDS, plain decimal digits only."""
    match = re.fullmatch(r"([1-9][0-9]*)/([1-9][0-9]*)", value.strip())
    if not match:
        raise ShardError(f"shard must be INDEX/TOTAL with positive integers (e.g. 2/4), got {value!r}")
    index, total = int(match.group(1)), int(match.group(2))
    if total > MAX_SHARDS:
        raise ShardError(f"shard total {total} exceeds the maximum of {MAX_SHARDS}")
    if index > total:
        raise ShardError(f"shard index {index} is greater than the shard total {total}")
    return index, total


def read_inventory(path: Path, container_root: str = CONTAINER_ROOT) -> list[TestFile]:
    """The canonical inventory from `phpunit --list-tests-xml` (PHPUnit 12 format)."""
    try:
        root = ET.parse(path).getroot()
    except (ET.ParseError, OSError) as error:
        raise ShardError(f"cannot read the PHPUnit inventory {path}: {error}") from error
    ns = {"p": "https://xml.phpunit.de/testSuite"}
    tests = root.find("p:tests", ns)
    if tests is None:
        raise ShardError(f"{path} is not a PHPUnit --list-tests-xml document")
    unsupported = [child.tag for child in tests if child.tag != f"{{{ns['p']}}}testClass"]
    if unsupported:
        # A .phpt or any other test shape this planner does not partition must
        # never be silently dropped.
        raise ShardError(f"the inventory contains entries this planner cannot shard: {sorted(set(unsupported))}")
    prefix = container_root.rstrip("/") + "/"
    files: dict[str, dict] = {}
    for klass in tests:
        name, file = klass.get("name") or "", klass.get("file") or ""
        if not name or not file.startswith(prefix):
            raise ShardError(f"test class {name or '?'} has a file outside {container_root}: {file or '(none)'}")
        relative = file[len(prefix):]
        count = len(klass.findall("p:testMethod", ns))
        if count == 0:
            raise ShardError(f"test class {name} lists no test cases")
        entry = files.setdefault(relative, {"classes": [], "tests": 0, "order": len(files)})
        entry["classes"].append(name)
        entry["tests"] += count
    if not files:
        raise ShardError("the PHPUnit inventory is empty")
    return [TestFile(path, tuple(entry["classes"]), entry["tests"], entry["order"]) for path, entry in files.items()]


def inventory_digest(files: list[TestFile]) -> str:
    lines = sorted(f"{item.path}\t{','.join(sorted(item.classes))}\t{item.tests}" for item in files)
    return hashlib.sha256("\n".join(lines).encode()).hexdigest()


def partition(files: list[TestFile], total: int) -> list[list[TestFile]]:
    """LPT by test count; every file in exactly one shard; canonical order within a shard."""
    if total < 1 or total > MAX_SHARDS:
        raise ShardError(f"shard total must be 1..{MAX_SHARDS}, got {total}")
    shards: list[list[TestFile]] = [[] for _ in range(total)]
    loads = [0] * total
    for item in sorted(files, key=lambda f: (-f.tests, f.path)):
        target = min(range(total), key=lambda index: (loads[index], index))
        shards[target].append(item)
        loads[target] += item.tests
    for shard in shards:
        shard.sort(key=lambda f: f.order)
    seen = Counter(item.path for shard in shards for item in shard)
    if set(seen) != {item.path for item in files} or any(count != 1 for count in seen.values()):
        raise ShardError("internal: the partition is not complete and disjoint")
    return shards


def plan(files: list[TestFile], index: int, total: int) -> dict:
    multi = [item.path for item in files if len(item.classes) != 1]
    if multi:
        # Per-class execution counts cannot be checked against a file total
        # shared by several classes: refused, never approximated.
        raise ShardError(f"test files declaring more than one test class: {multi[:5]}")
    if not 1 <= index <= total:
        raise ShardError(f"shard index {index} is outside 1..{total}")
    shards = partition(files, total)
    members = shards[index - 1]
    # The plan's identity is its membership; the order inside a shard is the
    # inventory's own and is deliberately not part of it.
    layout = [sorted(item.path for item in shard) for shard in shards]
    return {
        "shard": index,
        "total": total,
        "inventory_sha256": inventory_digest(files),
        "inventory_files": len(files),
        "inventory_tests": sum(item.tests for item in files),
        "plan_sha256": hashlib.sha256(json.dumps(layout).encode()).hexdigest(),
        "shard_tests": [sum(item.tests for item in shard) for shard in shards],
        "files": [item.path for item in members],
        "classes": {item.classes[0]: item.tests for item in members},
    }


def render_config(base_config: str, members: list[str], index: int, total: int) -> str:
    """phpunit.xml with <testsuites> replaced by this shard's files; everything else verbatim."""
    found = TESTSUITES.findall(base_config)
    if len(found) != 1:
        raise ShardError(f"expected exactly one <testsuites> block in phpunit.xml, found {len(found)}")
    for path in members:
        if not re.fullmatch(r"tests/[A-Za-z0-9_./-]+\.php", path) or ".." in path:
            raise ShardError(f"refusing an unexpected test path: {path!r}")
    indent = found[0]
    inner = indent + "    "
    lines = [f"{indent}<!-- Generated by infrastructure/ci/lycenza-ci shard plan (ADR 0072 section 31): shard {index} of {total}. Never commit. -->",
             f"{indent}<testsuites>", f'{inner}<testsuite name="Shard {index} of {total}">']
    lines += [f"{inner}    <file>{path}</file>" for path in members]
    lines += [f"{inner}</testsuite>", f"{indent}</testsuites>"]
    return TESTSUITES.sub(lambda _: "\n" + "\n".join(lines), base_config, count=1)


def junit_classes(path: Path) -> Counter[str]:
    """Per-class executed test-case counts from a PHPUnit JUnit report."""
    if not path.is_file():
        raise ShardError(f"no JUnit report at {path} (the shard did not finish)")
    try:
        root = ET.parse(path).getroot()
    except ET.ParseError as error:
        raise ShardError(f"the JUnit report {path} is not valid XML (the shard did not finish): {error}") from error
    return Counter(case.get("class") or case.get("classname") or "?" for case in root.iter("testcase"))


def _failing(outcomes: dict) -> dict:
    return {key: value for key, value in outcomes.items() if key in ("failure", "error")}


def verify(shard_plan: dict, junit_path: Path) -> dict:
    """One shard executed exactly its planned classes with exactly the planned case counts."""
    executed = junit_classes(junit_path)
    summary = junit.digest(junit_path)
    planned = Counter(shard_plan["classes"])
    problems = [f"{klass}: planned {planned.get(klass, 0)}, executed {executed.get(klass, 0)}"
                for klass in sorted(set(planned) | set(executed)) if planned.get(klass, 0) != executed.get(klass, 0)]
    if summary["duplicate_ids"]:
        problems.append(f"duplicate test ids: {summary['duplicate_ids'][:5]}")
    if _failing(summary["outcomes"]):
        problems.append(f"the shard has failing tests: {_failing(summary['outcomes'])}")
    if problems:
        raise ShardError(f"shard {shard_plan['shard']}/{shard_plan['total']} did not execute its plan: " + "; ".join(problems[:20]))
    return summary


@dataclass(frozen=True)
class ShardBundle:
    """One shard's uploaded output: its plan, the inventory it planned from, its JUnit report."""
    report: dict
    inventory: list[TestFile]
    junit_path: Path


def coverage(bundles: list[ShardBundle]) -> dict:
    """Across one run's shards: same inventory, indices exactly 1..N, union = inventory, once each."""
    if not bundles:
        raise ShardError("no shard output was found (missing shard output)")
    totals = {bundle.report["total"] for bundle in bundles}
    if len(totals) != 1:
        raise ShardError(f"shards disagree on the shard total: {sorted(totals)}")
    total = totals.pop()
    indices = sorted(bundle.report["shard"] for bundle in bundles)
    if indices != list(range(1, total + 1)):
        raise ShardError(f"expected shard outputs 1..{total} exactly once each, found {indices}")
    digests = {inventory_digest(bundle.inventory) for bundle in bundles}
    claimed = {bundle.report["inventory_sha256"] for bundle in bundles}
    if len(digests) != 1 or claimed != digests:
        raise ShardError("the shards did not plan from one identical inventory")
    files = bundles[0].inventory
    inventory_classes = {item.classes[0]: item.tests for item in files}
    planned: Counter[str] = Counter()
    executed: Counter[str] = Counter()
    for bundle in sorted(bundles, key=lambda b: b.report["shard"]):
        expected = plan(files, bundle.report["shard"], total)
        for key in ("plan_sha256", "files", "classes"):
            if bundle.report.get(key) != expected[key]:
                raise ShardError(f"shard {bundle.report['shard']}: `{key}` is not the deterministic plan of the inventory")
        overlap = set(planned) & set(expected["classes"])
        if overlap:
            raise ShardError(f"classes planned in more than one shard: {sorted(overlap)[:5]}")
        planned.update(expected["classes"])
        ran = junit_classes(bundle.junit_path)
        if ran != Counter(expected["classes"]):
            raise ShardError(f"shard {bundle.report['shard']}'s JUnit report does not match its plan")
        executed.update(ran)
    if dict(planned) != inventory_classes:
        raise ShardError(f"the shard plans do not cover the inventory exactly (missing: {sorted(set(inventory_classes) - set(planned))[:5]})")
    if dict(executed) != inventory_classes:
        raise ShardError("the union of the executed shards is not the canonical inventory")
    union = junit.digest(*[bundle.junit_path for bundle in bundles])
    inventory_tests = sum(item.tests for item in files)
    if union["tests"] != inventory_tests or union["duplicate_ids"]:
        raise ShardError(f"the union ran {union['tests']} test cases (duplicates: {union['duplicate_ids'][:5]}); the inventory has {inventory_tests}")
    if _failing(union["outcomes"]):
        raise ShardError(f"the union has failing tests: {_failing(union['outcomes'])}")
    return {"total": total, "inventory_sha256": digests.pop(), "inventory_files": len(files), "inventory_tests": inventory_tests,
            "shard_tests": bundles[0].report["shard_tests"], "union": union}
