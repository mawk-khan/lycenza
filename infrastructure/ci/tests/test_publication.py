"""ADR 0072 sections 3, 13, 14 (E03.1): the exact-SHA publication verdict."""

from __future__ import annotations

import json
import sys
import unittest
from datetime import UTC, datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci.contract import REPO_ROOT  # noqa: E402
from lycenza_ci.publication import decide, earliest_expiry  # noqa: E402

EXCEPTIONS_FILE = REPO_ROOT / "infrastructure/release/vulnerability-exceptions.json"

CANDIDATE = "c" * 40
MAIN = "a" * 40
NOW = datetime(2026, 10, 12, 12, 0, tzinfo=UTC)
ACTIONS = {"id": 15368, "slug": "github-actions"}
DECISION = {"exceptions": [{"expires": "2026-11-10"}, {"expires": "2026-11-10"}]}


def run(conclusion: str = "success", *, sha: str = CANDIDATE, app: dict | None = None, completed: datetime | None = NOW - timedelta(hours=1),
        status: str = "completed", run_id: int = 1) -> dict:
    return {"id": run_id, "name": "CI gate", "head_sha": sha, "status": status, "conclusion": conclusion if status == "completed" else None,
            "started_at": (completed or NOW).isoformat().replace("+00:00", "Z"),
            "completed_at": completed.isoformat().replace("+00:00", "Z") if completed and status == "completed" else None,
            "app": app if app is not None else ACTIONS}


def verdict(**overrides):
    arguments = {"candidate": CANDIDATE, "main": MAIN, "main_is_ancestor": True, "check_runs": [run()],
                 "exceptions_document": DECISION, "now": NOW}
    arguments.update(overrides)
    return decide(**arguments)


class PublicationTest(unittest.TestCase):
    def test_publishable(self) -> None:
        result = verdict()
        self.assertTrue(result.publishable, result.problems)
        self.assertTrue(result.fast_forward)
        self.assertEqual(15368, result.gate["app_id"])
        self.assertEqual("2026-11-10", result.e16_earliest_expiry)
        self.assertEqual("2026-11-09", result.e16_last_valid_day)

    def test_another_shas_checks_never_count(self) -> None:
        self.assertFalse(verdict(check_runs=[run(sha="d" * 40)]).publishable)
        self.assertFalse(verdict(check_runs=[]).publishable)

    def test_only_github_actions_counts(self) -> None:
        forged = verdict(check_runs=[run(app={"id": 999, "slug": "someone-else"})])
        self.assertFalse(forged.publishable)
        self.assertEqual(1, len(forged.foreign_gate_runs))
        mixed = verdict(check_runs=[run(app={"id": 999, "slug": "x"}, run_id=2), run(run_id=1)])
        self.assertTrue(mixed.publishable, "a foreign run is ignored, the Actions run decides")

    def test_the_newest_actions_run_decides(self) -> None:
        later_failure = [run(completed=NOW - timedelta(hours=3), run_id=1), run("failure", completed=NOW - timedelta(hours=1), run_id=2)]
        self.assertFalse(verdict(check_runs=later_failure).publishable)
        later_success = [run("failure", completed=NOW - timedelta(hours=3), run_id=1), run(completed=NOW - timedelta(hours=1), run_id=2)]
        self.assertTrue(verdict(check_runs=later_success).publishable)
        self.assertFalse(verdict(check_runs=[run(status="in_progress", completed=None)]).publishable, "pending is not green")
        for conclusion in ("failure", "cancelled", "timed_out", "neutral", "skipped"):
            self.assertFalse(verdict(check_runs=[run(conclusion)]).publishable, conclusion)

    def test_stale_green_is_refused(self) -> None:
        self.assertFalse(verdict(check_runs=[run(completed=NOW - timedelta(hours=73))]).publishable)
        self.assertTrue(verdict(check_runs=[run(completed=NOW - timedelta(hours=71))]).publishable)
        self.assertFalse(verdict(check_runs=[run(completed=NOW - timedelta(hours=2))], max_age_hours=1).publishable)

    def test_e16_expiry_refuses_an_old_green_result(self) -> None:
        # Checked green while the decision was valid, published after it expired.
        expired_now = datetime(2026, 11, 10, 0, 30, tzinfo=UTC)
        result = verdict(now=expired_now, check_runs=[run(completed=datetime(2026, 11, 9, 23, 0, tzinfo=UTC))])
        self.assertFalse(result.publishable)
        self.assertTrue(any("E16" in problem for problem in result.problems))
        # The last valid day is still publishable.
        last_day = datetime(2026, 11, 9, 23, 30, tzinfo=UTC)
        self.assertTrue(verdict(now=last_day, check_runs=[run(completed=datetime(2026, 11, 9, 23, 0, tzinfo=UTC))]).publishable)

    def test_the_repository_decision_boundary_is_exclusive(self) -> None:
        """E03.3: the real decision file at this tree. Its last valid day passes;
        its expiry day refuses, even with a green gate from the day before."""
        document = json.loads(EXCEPTIONS_FILE.read_text(encoding="utf-8"))
        expiry = earliest_expiry(document)
        self.assertIsNotNone(expiry, "the decision has records, so it has an expiry")
        last_day = expiry - timedelta(days=1)
        gate_at = datetime(last_day.year, last_day.month, last_day.day, 0, 30, tzinfo=UTC)
        on_last_day = verdict(exceptions_document=document, now=gate_at + timedelta(hours=23), check_runs=[run(completed=gate_at)])
        self.assertTrue(on_last_day.publishable, on_last_day.problems)
        self.assertEqual(last_day.isoformat(), on_last_day.e16_last_valid_day)
        on_expiry = verdict(exceptions_document=document, now=gate_at + timedelta(hours=24), check_runs=[run(completed=gate_at)])
        self.assertFalse(on_expiry.publishable)
        self.assertTrue(any("E16" in problem for problem in on_expiry.problems))
        # OWNER-0O-E16-2026-10-11 specifically: valid through 2026-11-09, refused from
        # 2026-11-10. A successor decision changes the dates; the rule above still holds.
        if {record.get("approved_by") for record in document["exceptions"]} == {"OWNER-0O-E16-2026-10-11"}:
            self.assertEqual("2026-11-10", expiry.isoformat())
            self.assertEqual("2026-11-09", on_last_day.e16_last_valid_day)

    def test_the_earliest_record_expiry_governs(self) -> None:
        self.assertEqual("2026-10-20", earliest_expiry({"exceptions": [{"expires": "2026-11-10"}, {"expires": "2026-10-20"}]}).isoformat())
        result = verdict(now=datetime(2026, 10, 21, tzinfo=UTC), exceptions_document={"exceptions": [{"expires": "2026-11-10"}, {"expires": "2026-10-20"}]},
                         check_runs=[run(completed=datetime(2026, 10, 20, 23, tzinfo=UTC))])
        self.assertFalse(result.publishable)

    def test_unreadable_or_missing_decision_fails_closed(self) -> None:
        self.assertFalse(verdict(exceptions_document=None).publishable)
        self.assertFalse(verdict(exceptions_document={"exceptions": [{"expires": "not-a-date"}]}).publishable)
        self.assertFalse(verdict(exceptions_document={}).publishable)
        self.assertTrue(verdict(exceptions_document={"exceptions": []}).publishable, "no exception -> no expiry constraint")

    def test_fast_forward_only(self) -> None:
        self.assertFalse(verdict(main_is_ancestor=False).publishable, "main moved: rebase and re-check")
        self.assertFalse(verdict(main=CANDIDATE).publishable, "nothing to publish")


if __name__ == "__main__":
    unittest.main()
