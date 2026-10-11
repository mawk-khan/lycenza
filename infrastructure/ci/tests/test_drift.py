"""ADR 0072 section 19 (E03.1): drift detection against the pinned contract."""

from __future__ import annotations

import copy
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci.contract import load_ruleset  # noqa: E402
from lycenza_ci.drift import assess  # noqa: E402

STATE_FILE = Path(__file__).resolve().parents[1] / "main-protection-state.json"


def live(contract: dict) -> tuple[list[dict], list[dict]]:
    """What GitHub would report if the contract were applied exactly."""
    effective = [dict(rule, ruleset_id=7, ruleset_source_type="Repository") for rule in contract["rules"]]
    ruleset = dict(copy.deepcopy(contract), id=7)
    return effective, [ruleset]


class DriftTest(unittest.TestCase):
    def setUp(self) -> None:
        self.contract = load_ruleset()

    def test_the_expected_state_is_awaiting_activation_until_e03_2(self) -> None:
        self.assertEqual("awaiting-activation", json.loads(STATE_FILE.read_text(encoding="utf-8"))["expected_state"])

    def test_awaiting_activation_reports_and_passes_with_nothing_live(self) -> None:
        report = assess("awaiting-activation", self.contract, [], [])
        self.assertTrue(report.ok)
        self.assertTrue(report.observed["contract_findings_while_awaiting_activation"])

    def test_active_and_exactly_as_contracted(self) -> None:
        effective, rulesets = live(self.contract)
        report = assess("active", self.contract, effective, rulesets)
        self.assertTrue(report.ok, report.findings)
        self.assertEqual([], report.unverified)

    def test_active_detects_each_kind_of_drift(self) -> None:
        self.assertFalse(assess("active", self.contract, [], []).ok, "no active ruleset")
        for missing in ("deletion", "non_fast_forward", "required_linear_history", "required_status_checks"):
            effective, rulesets = live(self.contract)
            effective = [rule for rule in effective if rule["type"] != missing]
            self.assertFalse(assess("active", self.contract, effective, rulesets).ok, missing)

        effective, rulesets = live(self.contract)
        for rule in effective:
            if rule["type"] == "required_status_checks":
                rule["parameters"] = {"required_status_checks": [{"context": "CI gate"}]}  # any source
        self.assertFalse(assess("active", self.contract, effective, rulesets).ok, "source pin removed")

        effective, rulesets = live(self.contract)
        rulesets[0]["bypass_actors"] = [{"actor_id": 5, "actor_type": "RepositoryRole", "bypass_mode": "always"}]
        self.assertFalse(assess("active", self.contract, effective, rulesets).ok, "bypass actor")

        effective, rulesets = live(self.contract)
        rulesets[0]["enforcement"] = "evaluate"
        self.assertFalse(assess("active", self.contract, effective, rulesets).ok, "not enforced")

    def test_invisible_bypass_actors_are_unverified_never_passed_silently(self) -> None:
        effective, rulesets = live(self.contract)
        del rulesets[0]["bypass_actors"]
        report = assess("active", self.contract, effective, rulesets)
        self.assertEqual(1, len(report.unverified))

    def test_unknown_state_fails(self) -> None:
        self.assertFalse(assess("maybe", self.contract, [], []).ok)


if __name__ == "__main__":
    unittest.main()
