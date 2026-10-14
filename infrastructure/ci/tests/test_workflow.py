"""ADR 0072 sections 6-12 (E03.1): ci.yml wires the classifier, the lanes and the
aggregate `CI gate` exactly as gate.py expects, and the docs lane's PHPUnit
group is exhaustive. Text checks (the runner's Python has no YAML library)."""

from __future__ import annotations

import re
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci.classify import ALWAYS  # noqa: E402
from lycenza_ci.contract import GATE_CONTEXT, REPO_ROOT  # noqa: E402
from lycenza_ci.gate import CLASSIFIER_JOB, JOB_LANES  # noqa: E402

WORKFLOW = (REPO_ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
ALWAYS_JOBS = {job for job, lane in JOB_LANES.items() if lane in ALWAYS}


def jobs() -> dict[str, str]:
    body = WORKFLOW.split("\njobs:\n", 1)[1]
    parts = re.split(r"^  ([a-z][a-z0-9-]*):\n", body, flags=re.M)
    return {parts[i]: parts[i + 1] for i in range(1, len(parts), 2)}


class WorkflowTest(unittest.TestCase):
    def test_candidates_on_integration_branches_main_and_pull_requests_run_ci(self) -> None:
        self.assertIn('  push:\n    branches: [main, "integration/**"]\n  pull_request:\n', WORKFLOW)

    def test_the_job_set_matches_the_gate(self) -> None:
        self.assertEqual(set(JOB_LANES) | {CLASSIFIER_JOB, "gate"}, set(jobs()))

    def test_the_gate_job(self) -> None:
        gate = jobs()["gate"]
        self.assertIn(f"    name: {GATE_CONTEXT}\n", gate)
        self.assertIn("    if: always()\n", gate)
        needs = re.findall(r"^      - ([a-z-]+)$", gate.split("    needs:\n", 1)[1].split("    runs-on:", 1)[0], flags=re.M)
        self.assertEqual(sorted(set(JOB_LANES) | {CLASSIFIER_JOB}), sorted(needs))
        self.assertIn("NEEDS_JSON: ${{ toJSON(needs) }}", gate)
        self.assertIn("infrastructure/ci/lycenza-ci gate", gate)

    def test_lane_jobs_run_exactly_when_the_classifier_requires_them(self) -> None:
        for job, lane in JOB_LANES.items():
            body = jobs()[job]
            condition = re.findall(r"^    if: (.+)$", body, flags=re.M)
            if job in ALWAYS_JOBS:
                self.assertEqual([], condition, f"{job} is an always lane: no condition")
            else:
                self.assertEqual([f"contains(fromJSON(needs.classify.outputs.lanes), '{lane}')"], condition, job)
                expected = "    needs: [classify, platform]\n" if job == "platform-coverage" else "    needs: classify\n"
                self.assertIn(expected, body, job)

    def test_no_job_decides_its_own_failure_is_optional(self) -> None:
        self.assertNotIn("continue-on-error", WORKFLOW)
        swallowed = [line.strip() for line in WORKFLOW.splitlines() if "|| true" in line and not line.strip().startswith(("summary=", "failed="))]
        self.assertEqual([], swallowed, "a failure is swallowed outside the PHPUnit summary extraction")

    def test_the_laravel_job_runs_the_canonical_runner(self) -> None:
        platform = jobs()["platform"]
        env_first = platform.index("cp apps/platform/.env.example apps/platform/.env")
        composer = platform.index("composer install")
        self.assertLess(env_first, composer, "the environment file exists before Composer can boot Laravel")
        self.assertRegex(platform, r"composer install [^\n]*--no-scripts[^\n]*--no-plugins")
        self.assertIn("php artisan package:discover", platform)
        self.assertIn("apps/platform/bin/safe-test --reset-db", platform)
        self.assertIn("SAFE_TEST_COMPOSE_OVERRIDE: ${{ github.workspace }}/infrastructure/ci/compose.s3-versitygw.yml", platform)
        # Option A (ADR 0072 section 31): the canonical suite, sharded -- never a subset.
        self.assertRegex(platform, r'apps/platform/bin/safe-test --shard "\$SHARD" --log-junit \S+ 2>&1 \| tee')
        self.assertIn("SHARD: ${{ matrix.shard }}/${{ strategy.job-total }}", platform)
        self.assertNotRegex(platform, r"safe-test [^\n]*--(group|testsuite|exclude-group|configuration|filter)", "the full suite, never a subset")
        self.assertNotIn("--filter", platform, "the full suite, never a subset")
        self.assertNotIn("continue-on-error", platform)

    def test_the_shard_matrix(self) -> None:
        platform = jobs()["platform"]
        self.assertIn("    strategy:\n      fail-fast: false\n      matrix:\n", platform, "every shard reports; none is cancelled by another")
        shards = re.findall(r"^        shard: \[([0-9, ]+)\]$", platform, flags=re.M)
        self.assertEqual(1, len(shards))
        numbers = [int(value) for value in shards[0].split(",")]
        self.assertEqual(list(range(1, len(numbers) + 1)), numbers, "shard indices are exactly 1..TOTAL")
        self.assertGreater(len(numbers), 1)
        self.assertIn("lycenza-ci shard verify", platform)
        self.assertIn("if-no-files-found: error", platform)
        self.assertIn("name: phpunit-shard-${{ matrix.shard }}", platform)
        self.assertIn("name: Laravel (apps/platform) shard ${{ matrix.shard }}/${{ strategy.job-total }}", platform)

    def test_the_coverage_job_proves_the_union(self) -> None:
        coverage = jobs()["platform-coverage"]
        self.assertIn("pattern: phpunit-shard-*", coverage)
        self.assertIn('infrastructure/ci/lycenza-ci shard coverage --dir "$RUNNER_TEMP/shards"', coverage)
        self.assertNotIn("merge-multiple", coverage, "one directory per shard")

    def test_the_static_checks_run_once_for_the_lane(self) -> None:
        static = jobs()["platform-static"]
        for check in ("npm run type-check", "npm run lint", "npm run format:check", "npm run build", "vendor/bin/pint --test", "vendor/bin/phpstan analyse"):
            self.assertIn(check, static, check)
        self.assertRegex(static, r"composer install [^\n]*--no-scripts[^\n]*--no-plugins")

    def test_main_push_prior_validation_is_read_only(self) -> None:
        classify_job = jobs()["classify"]
        self.assertNotIn("permissions:", classify_job, "no workflow permission is added for the lookup")
        self.assertNotIn("GITHUB_TOKEN", WORKFLOW)

    def test_supply_chain_audits_block_on_policy(self) -> None:
        self.assertIn("qualify audit --fail-on-blocking", jobs()["supply-chain"])

    def test_history_reading_jobs_fetch_full_history(self) -> None:
        for job in ("classify", "secrets-scan", "supply-chain", "integrity"):
            self.assertIn("fetch-depth: 0", jobs()[job], job)

    def test_flutter_is_pinned(self) -> None:
        self.assertRegex(jobs()["mobile"], r"flutter-version: \d+\.\d+\.\d+\n")


class DocsLaneInventoryTest(unittest.TestCase):
    """Every PHPUnit test that reads docs/** is in the `repository-documents` group, and only those."""

    READS_DOCS = re.compile(r"""(dirname\(base_path\(\), 2\)\.'/docs/|repo\('docs/|base_path\('\.\./\.\./docs)""")
    GROUP = "#[Group('repository-documents')]"

    def test_the_group_is_exactly_the_documentation_readers(self) -> None:
        readers, grouped = set(), set()
        for path in (REPO_ROOT / "apps/platform/tests").rglob("*.php"):
            text = path.read_text(encoding="utf-8")
            code = "\n".join(line for line in text.splitlines() if not line.lstrip().startswith(("*", "//", "/*", "#[")))
            relative = str(path.relative_to(REPO_ROOT))
            if self.READS_DOCS.search(code):
                readers.add(relative)
            if self.GROUP in text:
                grouped.add(relative)
        self.assertTrue(readers, "the inventory found the documentation-reading tests")
        self.assertEqual(sorted(readers), sorted(grouped))

    def test_the_docs_lane_runs_that_group(self) -> None:
        self.assertIn("apps/platform/bin/safe-test --group repository-documents", jobs()["docs-guards"])


if __name__ == "__main__":
    unittest.main()
