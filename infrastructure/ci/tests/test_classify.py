"""ADR 0072 sections 6-7, 12, 22 (E03.1): the central path classifier."""

from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci.classify import ALL, ALWAYS, CLASS_LANES, LANES, STRICT, already_validated, classify, parse_name_status, path_class, prior_integration_validation  # noqa: E402
from lycenza_ci.contract import REPO_ROOT  # noqa: E402


def lanes(*paths: str) -> set[str]:
    return set(classify(list(paths)).lanes)


class ClassifierTest(unittest.TestCase):
    def test_strict_is_every_lane_but_the_subsumed_docs_lane(self) -> None:
        self.assertEqual(ALL - {"docs"}, STRICT)
        self.assertIn("platform", STRICT, "the full suite runs every documentation guard")

    def test_every_lane_is_always_or_reachable(self) -> None:
        self.assertEqual(set(LANES), set(ALL))
        reachable = set().union(*CLASS_LANES.values())
        self.assertEqual(set(LANES), reachable | set(ALWAYS))

    def test_docs_only_still_runs_the_docs_lane_and_every_always_lane(self) -> None:
        self.assertEqual(ALWAYS | {"docs"}, lanes("docs/roadmap/MASTER-ROADMAP.md", "CLAUDE.md", "README.md"))

    def test_docs_lane_is_never_empty_of_meaning(self) -> None:
        result = classify(["docs/security/SOMETHING.md"])
        self.assertTrue(ALWAYS <= result.lanes, "gitleaks, supply chain (release tooling tests) and integrity always run")
        self.assertIn("docs", result.lanes)
        self.assertFalse(result.strict)

    def test_application_code_runs_the_full_suite_and_images(self) -> None:
        self.assertEqual(ALWAYS | {"platform", "images"}, lanes("apps/platform/app/Models/School.php"))
        self.assertEqual(ALWAYS | {"platform", "images"}, lanes("apps/platform/resources/js/app.ts"))

    def test_the_full_suite_subsumes_the_docs_lane(self) -> None:
        self.assertEqual(ALWAYS | {"platform", "images"}, lanes("docs/x.md", "apps/platform/routes/web.php"))

    def test_gateway_contracts_shared_types_and_mobile(self) -> None:
        self.assertEqual(ALWAYS | {"gateway", "images", "platform"}, lanes("services/ai/app/main.py"))
        self.assertEqual(ALWAYS | {"shared_types", "platform"}, lanes("packages/contracts/openapi/school-os-api.yaml"))
        self.assertEqual(ALWAYS | {"shared_types"}, lanes("packages/shared-types/src/index.ts"))
        self.assertEqual(ALWAYS | {"mobile"}, lanes("apps/mobile/lib/main.dart"))

    def test_mobile_is_not_required_by_unrelated_changes(self) -> None:
        for path in ("apps/platform/app/X.php", "services/ai/app/x.py", "docs/x.md", "infrastructure/release/x.py"):
            self.assertNotIn("mobile", lanes(path), path)

    def test_release_and_image_inputs(self) -> None:
        self.assertEqual(ALWAYS | {"images", "platform"}, lanes("infrastructure/release/vulnerability-exceptions.json"))
        self.assertEqual(ALWAYS | {"images", "platform"}, lanes("infrastructure/docker/production/app.Dockerfile"))

    def test_ci_definition_and_tooling_are_strictest(self) -> None:
        for path in (".github/workflows/ci.yml", ".github/workflows/protection-drift.yml",
                     "infrastructure/ci/lycenza_ci/classify.py", "infrastructure/ci/compose.s3-versitygw.yml",
                     "docker-compose.yml", ".gitleaks.toml"):
            result = classify([path])
            self.assertEqual(STRICT, result.lanes, path)
            self.assertTrue(result.strict, path)

    def test_a_workflow_change_never_classifies_as_docs_only(self) -> None:
        result = classify(["docs/a.md", ".github/workflows/ci.yml"])
        self.assertEqual(STRICT, result.lanes)

    def test_unknown_paths_fail_closed(self) -> None:
        for path in ("newtopdir/thing.txt", "Makefile", "apps/newapp/x"):
            self.assertEqual("unknown", path_class(path), path)
            self.assertEqual(STRICT, lanes(path), path)

    def test_other_infrastructure_is_deliberately_strict(self) -> None:
        self.assertEqual("infrastructure", path_class("infrastructure/terraform/main.tf"))
        self.assertEqual(STRICT, lanes("infrastructure/terraform/main.tf"))
        self.assertTrue(classify(["infrastructure/terraform/main.tf"]).strict)

    def test_no_base_or_empty_diff_requires_every_lane(self) -> None:
        self.assertEqual(STRICT, classify(None).lanes)
        self.assertTrue(classify(None).strict)
        self.assertEqual(STRICT, classify([]).lanes)

    def test_dev_tooling_needs_only_the_always_lanes(self) -> None:
        self.assertEqual(set(ALWAYS), lanes(".claude/skills/publish-gate/run.sh", ".ddev/config.yaml", "docker-compose.phase1b.yml"))

    def test_renames_and_deletions_classify_both_sides(self) -> None:
        output = "R100\0docs/old.md\0apps/platform/app/New.php\0D\0services/ai/app/gone.py\0M\0README.md\0"
        self.assertEqual(["docs/old.md", "apps/platform/app/New.php", "services/ai/app/gone.py", "README.md"], parse_name_status(output))
        self.assertEqual(ALWAYS | {"platform", "images", "gateway"}, lanes(*parse_name_status(output)))
        # A rename that moves a file OUT of the strict CI area still requires every lane.
        self.assertEqual(STRICT, lanes(*parse_name_status("R090\0.github/workflows/ci.yml\0docs/ci.yml\0")))

    def test_no_bytecode_is_tracked(self) -> None:
        tracked = subprocess.run(["git", "-C", str(REPO_ROOT), "ls-files", "-z"], capture_output=True, check=True).stdout.decode()
        self.assertEqual([], [path for path in tracked.split("\0") if "__pycache__/" in path or path.endswith(".pyc")])

    def test_every_tracked_path_has_a_deliberate_class(self) -> None:
        tracked = subprocess.run(["git", "-C", str(REPO_ROOT), "ls-files", "-z"], capture_output=True, check=True).stdout.decode()
        unknown = sorted(path for path in tracked.split("\0") if path and path_class(path) == "unknown")
        self.assertEqual([], unknown[:20], "a tracked path falls through to `unknown`; give it a deliberate class (or keep it strict on purpose)")


class PriorIntegrationValidationTest(unittest.TestCase):
    """ADR 0072 section 31 (Option A): a push to `main` skips the heavy lanes only
    for an exact SHA whose newest Actions `CI gate` run from an integration/**
    check suite succeeded; anything else is the ordinary classification."""

    SHA = "a" * 40

    def run_(self, run_id: int, conclusion: str = "success", completed: str = "2026-10-14T10:00:00Z", suite: int = 1,
             app: int = 15368, sha: str | None = None, name: str = "CI gate", status: str = "completed") -> dict:
        return {"id": run_id, "name": name, "head_sha": sha or self.SHA, "status": status, "conclusion": conclusion,
                "completed_at": completed, "app": {"id": app}, "check_suite": {"id": suite}}

    def suites(self, **branches: str) -> dict[int, dict]:
        return {int(key[1:]): {"head_branch": branch, "head_sha": self.SHA, "app": {"id": 15368}} for key, branch in branches.items()}

    def decide(self, runs: list[dict], suites: dict[int, dict]) -> str | None:
        return prior_integration_validation(self.SHA, runs, suites, 15368, "CI gate")

    def test_a_green_integration_run_validates_the_sha(self) -> None:
        self.assertIsNotNone(self.decide([self.run_(1)], self.suites(s1="integration/x")))
        self.assertEqual(ALWAYS, already_validated("run 1").lanes)

    def test_anything_else_is_the_ordinary_classification(self) -> None:
        integration = self.suites(s1="integration/x")
        self.assertIsNone(self.decide([], integration), "no run")
        self.assertIsNone(self.decide([self.run_(1, conclusion="failure")], integration), "red")
        self.assertIsNone(self.decide([self.run_(1, conclusion="cancelled")], integration), "cancelled")
        self.assertIsNone(self.decide([self.run_(1, status="in_progress", conclusion=None)], integration), "unfinished")
        self.assertIsNone(self.decide([self.run_(1, app=999)], integration), "another app")
        self.assertIsNone(self.decide([self.run_(1, sha="b" * 40)], integration), "another SHA")
        self.assertIsNone(self.decide([self.run_(1, name="CI gate (fake)")], integration), "another check")
        self.assertIsNone(self.decide([self.run_(1)], self.suites(s1="main")), "the main run itself")
        self.assertIsNone(self.decide([self.run_(1)], self.suites(s1="feature/x")), "not an integration branch")
        self.assertIsNone(self.decide([self.run_(1)], {}), "suite unknown")
        foreign_suite = {1: {"head_branch": "integration/x", "head_sha": self.SHA, "app": {"id": 999}}}
        self.assertIsNone(self.decide([self.run_(1)], foreign_suite), "suite from another app")

    def test_the_newest_integration_run_decides(self) -> None:
        suites = self.suites(s1="integration/x", s2="integration/x")
        older_green = self.run_(1, completed="2026-10-14T09:00:00Z", suite=1)
        newer_red = self.run_(2, conclusion="failure", completed="2026-10-14T10:00:00Z", suite=2)
        self.assertIsNone(self.decide([older_green, newer_red], suites))
        newer_green = self.run_(3, completed="2026-10-14T11:00:00Z", suite=2)
        self.assertIsNotNone(self.decide([older_green, newer_red, newer_green], suites))


if __name__ == "__main__":
    unittest.main()
