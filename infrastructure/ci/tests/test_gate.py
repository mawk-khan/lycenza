"""ADR 0072 section 6 (E03.1): the aggregate `CI gate` decision, including the
mutation cases -- every required lane's failure makes the gate fail."""

from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci.classify import ALWAYS, STRICT, classify  # noqa: E402
from lycenza_ci.gate import JOB_LANES, decide  # noqa: E402

LANE_JOBS = {lane: job for job, lane in JOB_LANES.items()}


def needs_for(lanes: set[str], **overrides: str) -> dict:
    needs: dict = {"classify": {"result": "success", "outputs": {"lanes": json.dumps(sorted(lanes)), "base": "abc", "strict": "false"}}}
    for job, lane in JOB_LANES.items():
        needs[job] = {"result": "success" if lane in lanes else "skipped", "outputs": {}}
    for job, result in overrides.items():
        needs[job.replace("_", "-")]["result"] = result
    return needs


class GateTest(unittest.TestCase):
    def test_green_when_required_lanes_succeed_and_the_rest_are_skipped(self) -> None:
        for paths in (["docs/a.md"], ["apps/platform/app/X.php"], ["apps/mobile/lib/main.dart"], [".github/workflows/ci.yml"]):
            lanes = set(classify(paths).lanes)
            verdict = decide(needs_for(lanes))
            self.assertTrue(verdict.passed, f"{paths}: {verdict.problems}")

    def test_every_required_lane_failure_fails_the_gate(self) -> None:
        for lane in sorted(STRICT):
            for bad in ("failure", "cancelled", "skipped", None):
                needs = needs_for(set(STRICT))
                needs[LANE_JOBS[lane]]["result"] = bad
                verdict = decide(needs)
                self.assertFalse(verdict.passed, f"{lane} {bad}")

    def test_named_mutations(self) -> None:
        code = set(classify(["apps/platform/app/X.php"]).lanes)
        self.assertFalse(decide(needs_for(code, platform="failure")).passed, "Laravel red -> gate red")
        self.assertFalse(decide(needs_for(code, secrets_scan="failure")).passed, "gitleaks red -> gate red")
        self.assertFalse(decide(needs_for(code, supply_chain="failure")).passed, "supply chain red -> gate red")
        self.assertFalse(decide(needs_for(code, production_images="failure")).passed, "images red -> gate red")
        self.assertFalse(decide(needs_for(code, platform="cancelled")).passed, "cancelled required job -> gate red")
        gateway = set(classify(["services/ai/app/main.py"]).lanes)
        self.assertFalse(decide(needs_for(gateway, ai_service="failure")).passed, "Gateway red -> gate red")
        shared = set(classify(["packages/shared-types/src/x.ts"]).lanes)
        self.assertFalse(decide(needs_for(shared, shared_types="failure")).passed, "shared types red -> gate red")
        docs = set(classify(["docs/a.md"]).lanes)
        self.assertFalse(decide(needs_for(docs, docs_guards="failure")).passed, "docs guards red -> gate red")
        self.assertFalse(decide(needs_for(docs, integrity="failure")).passed, "whitespace/tooling red -> gate red")

    def test_mobile_skip_is_legitimate_only_when_the_classifier_says_so(self) -> None:
        unrelated = set(classify(["apps/platform/app/X.php"]).lanes)
        self.assertNotIn("mobile", unrelated)
        self.assertTrue(decide(needs_for(unrelated)).passed, "mobile skipped on an unrelated change -> gate can pass")
        mobile = set(classify(["apps/mobile/lib/main.dart"]).lanes)
        self.assertFalse(decide(needs_for(mobile, mobile="failure")).passed, "mobile change with Flutter red -> gate red")
        self.assertFalse(decide(needs_for(mobile, mobile="skipped")).passed, "mobile change cannot skip Flutter")

    def test_unknown_path_requires_the_strict_lanes(self) -> None:
        lanes = set(classify(["somewhere/new.txt"]).lanes)
        self.assertEqual(set(STRICT), lanes)
        for lane in STRICT:
            needs = needs_for(lanes)
            needs[LANE_JOBS[lane]]["result"] = "skipped"
            self.assertFalse(decide(needs).passed, lane)

    def test_a_lane_that_ran_without_being_required_fails_closed(self) -> None:
        needs = needs_for(set(ALWAYS) | {"docs"})
        needs["mobile"]["result"] = "success"
        self.assertFalse(decide(needs).passed)

    def test_classifier_problems_fail_closed(self) -> None:
        for broken in ({"result": "failure"}, {"result": "cancelled"}, {"result": "success", "outputs": {"lanes": "not json"}},
                       {"result": "success", "outputs": {"lanes": "{}"}}, {"result": "success", "outputs": {}}):
            needs = needs_for(set(STRICT))
            needs["classify"] = broken
            self.assertFalse(decide(needs).passed, broken)
        needs = needs_for(set(STRICT))
        del needs["classify"]
        self.assertFalse(decide(needs).passed)

    def test_the_classifier_cannot_drop_an_always_lane_or_invent_one(self) -> None:
        needs = needs_for({"supply_chain", "integrity", "docs"})
        needs["secrets-scan"]["result"] = "skipped"
        self.assertFalse(decide(needs).passed, "gitleaks is never optional")
        needs = needs_for(set(ALWAYS))
        needs["classify"]["outputs"]["lanes"] = json.dumps(sorted(ALWAYS | {"nonsense"}))
        self.assertFalse(decide(needs).passed)

    def test_the_job_set_must_be_exact(self) -> None:
        needs = needs_for(set(STRICT))
        del needs["platform"]
        self.assertFalse(decide(needs).passed, "a lane job missing from needs")
        needs = needs_for(set(STRICT))
        needs["surprise"] = {"result": "success"}
        self.assertFalse(decide(needs).passed, "an unexpected job")


class ShardedPlatformLaneTest(unittest.TestCase):
    """ADR 0072 section 31 (CI sharding Option A): the `platform` lane is three
    jobs. `platform` is the shard matrix; GitHub reports a matrix job's result
    as `success` only when every leg succeeded (a failed, cancelled or
    timed-out leg makes it `failure`/`cancelled`)."""

    PLATFORM_JOBS = ("platform-static", "platform", "platform-coverage")

    def test_the_platform_lane_is_exactly_the_three_jobs(self) -> None:
        self.assertEqual(set(self.PLATFORM_JOBS), {job for job, lane in JOB_LANES.items() if lane == "platform"})

    def test_any_platform_job_not_succeeding_fails_the_gate(self) -> None:
        code = set(classify(["apps/platform/app/X.php"]).lanes)
        self.assertTrue(decide(needs_for(code)).passed)
        for job in self.PLATFORM_JOBS:
            for bad in ("failure", "cancelled", "skipped", None):
                needs = needs_for(code)
                needs[job]["result"] = bad
                self.assertFalse(decide(needs).passed, f"{job} {bad}")
            needs = needs_for(code)
            del needs[job]
            self.assertFalse(decide(needs).passed, f"{job} missing from needs")

    def test_shards_cannot_run_when_the_lane_is_not_required(self) -> None:
        docs = set(classify(["docs/a.md"]).lanes)
        for job in self.PLATFORM_JOBS:
            needs = needs_for(docs)
            needs[job]["result"] = "success"
            self.assertFalse(decide(needs).passed, job)

    def test_a_main_push_validated_on_integration_needs_only_the_always_lanes(self) -> None:
        self.assertTrue(decide(needs_for(set(ALWAYS))).passed)
        needs = needs_for(set(ALWAYS))
        needs["supply-chain"]["result"] = "failure"
        self.assertFalse(decide(needs).passed, "the always lanes still decide a validated main push")


if __name__ == "__main__":
    unittest.main()
