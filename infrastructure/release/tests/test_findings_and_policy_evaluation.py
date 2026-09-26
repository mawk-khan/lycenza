"""Report normalization and the one vulnerability policy evaluator."""

from __future__ import annotations

import dataclasses
import json
import unittest

from lycenza_release import findings as fnd
from lycenza_release.evaluate import evaluate
from lycenza_release.exceptions import validate_exceptions
from lycenza_release.policy import load_policy
from lycenza_release.util import ReleaseError
from support import FIXTURES, TODAY, fixture


def grype_findings(image: str = "app") -> list[fnd.Finding]:
    return fnd.grype(json.loads((FIXTURES / "grype-findings.json").read_text().replace("__DIGEST__", "sha256:" + "0" * 64)), image)


class NormalizationTest(unittest.TestCase):
    def test_grype(self) -> None:
        found = {f.id: f for f in grype_findings()}
        self.assertEqual(found["CVE-2026-0001"].severity, "critical")
        self.assertEqual(found["CVE-2026-0001"].fix_state, "not-fixed")
        self.assertEqual(found["GHSA-2222-3333-4444"].fix_state, "fixed")
        self.assertIn("CVE-2026-0002", found["GHSA-2222-3333-4444"].ids)
        self.assertEqual(found["CVE-2026-0005"].severity, "negligible")

    def test_composer(self) -> None:
        self.assertEqual(fnd.composer_audit(fixture("composer-audit-clean.json"), {}, "high"), [])
        found = fnd.composer_audit(fixture("composer-audit-findings.json"), {"example/package": "1.2.3"}, "high")
        self.assertEqual([(f.id, f.severity, f.rated, f.version) for f in found],
                         [("CVE-2026-1111", "high", True, "1.2.3"), ("PKSA-mnop-qrst-uvwx", "high", False, "1.2.3")])

    def test_npm_counts_advisories_not_propagation(self) -> None:
        self.assertEqual(fnd.npm_audit(fixture("npm-audit-clean.json"), {}), [])
        found = fnd.npm_audit(fixture("npm-audit-findings.json"), {"example-lib": "3.0.0"})
        self.assertEqual([(f.id, f.package, f.severity, f.version) for f in found], [("GHSA-8888-9999-cccc", "example-lib", "medium", "3.0.0")])

    def test_pip_audit_has_no_severity_so_the_policy_value_applies(self) -> None:
        self.assertEqual(fnd.pip_audit(fixture("pip-audit-clean.json"), "high"), [])
        found = fnd.pip_audit(fixture("pip-audit-findings.json"), "high")
        self.assertEqual((found[0].severity, found[0].rated, found[0].fix_state), ("high", False, "fixed"))

    def test_duplicates_are_dropped(self) -> None:
        report = fixture("pip-audit-findings.json")
        report["dependencies"][0]["vulns"] *= 2
        self.assertEqual(len(fnd.pip_audit(report, "high")), 1)

    def test_malformed_and_incomplete_reports_fail_closed(self) -> None:
        for call in (lambda: fnd.grype({"matches": "x"}, "app"), lambda: fnd.grype([], "app"),
                     lambda: fnd.composer_audit({"advisories": ["x"]}, {}, "high"), lambda: fnd.npm_audit({}, {}),
                     lambda: fnd.pip_audit({"dependencies": None}, "high"),
                     lambda: fnd.grype({"matches": [{"vulnerability": {"severity": "Severe"}, "artifact": {}}]}, "app")):
            with self.assertRaises(ReleaseError):
                call()
        with self.assertRaises(ReleaseError) as caught:
            fnd.pip_audit({"dependencies": [{"name": "x", "version": "1", "skip_reason": "not on PyPI"}]}, "high")
        self.assertEqual(caught.exception.code, "audit_incomplete")


class EvaluatorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.policy = load_policy()

    def exceptions(self, **changes: str) -> list:
        document = fixture("exceptions/valid.json")
        document["exceptions"][0].update(changes)
        valid, errors = validate_exceptions(document, self.policy, TODAY)
        self.assertEqual(errors, [])
        return valid

    def test_blocking_rules(self) -> None:
        result = evaluate(grype_findings(), [])
        self.assertEqual(result["verdict"], "FAIL")
        reasons = {r["id"]: r["reason"] for r in result["blocking"]}
        self.assertEqual(reasons, {"CVE-2026-0001": "critical", "GHSA-2222-3333-4444": "high_fix_available",
                                   "CVE-2026-0003": "high_requires_exception"})
        self.assertEqual(result["counts"]["app:medium"], 1)

    def test_medium_and_below_are_recorded_not_blocking(self) -> None:
        recorded = [f for f in grype_findings() if f.severity in ("medium", "negligible")]
        self.assertEqual(evaluate(recorded, [])["verdict"], "PASS")

    def test_a_matching_exception_covers_only_its_exact_finding(self) -> None:
        high_no_fix = [f for f in grype_findings() if f.id == "CVE-2026-0003"]
        self.assertEqual(evaluate(high_no_fix, self.exceptions())["verdict"], "PASS")
        for change in ({"version": "3.0-2"}, {"package": "libexample"}, {"image": "ai-gateway"}, {"severity": "medium", "expires": "2026-10-15"}):
            with self.subTest(change):
                self.assertEqual(evaluate(high_no_fix, self.exceptions(**change))["verdict"], "FAIL")

    def test_an_exception_matches_an_alias(self) -> None:
        high_no_fix = [dataclasses.replace(f, fix_state="not-fixed") for f in grype_findings() if f.id == "GHSA-2222-3333-4444"]
        covered = self.exceptions(id="CVE-2026-0002", package="examplepkg", version="2.0.0", status="not_affected")
        result = evaluate(high_no_fix, covered)
        self.assertEqual(result["verdict"], "PASS")
        self.assertEqual(result["excepted"][0]["exception"], "CVE-2026-0002")

    def test_a_fix_becoming_available_stops_the_exception(self) -> None:
        # Phase 0O.6F recheck trigger: the same advisory/package/version gains a fix -> it blocks again
        # (High-with-fix must be fixed), naming the exception it supersedes; whatever the exception's status.
        gained_fix = [dataclasses.replace(f, fix_state="fixed") for f in grype_findings() if f.id == "CVE-2026-0003"]
        for status in ("accepted_risk", "not_affected", "false_positive"):
            with self.subTest(status):
                result = evaluate(gained_fix, [dataclasses.replace(e, status=status) for e in self.exceptions()])
                self.assertEqual(result["verdict"], "FAIL")
                self.assertEqual(result["blocking"][0]["reason"], "high_fix_available")
                self.assertEqual(result["blocking"][0]["superseded_exception"], "CVE-2026-0003")
                self.assertEqual(result["excepted"], [])
        high_fixed_alias = [f for f in grype_findings() if f.id == "GHSA-2222-3333-4444"]
        covered = self.exceptions(id="CVE-2026-0002", package="examplepkg", version="2.0.0", status="not_affected")
        self.assertEqual(evaluate(high_fixed_alias, covered)["verdict"], "FAIL")

    def test_a_new_finding_is_never_covered_by_an_existing_exception(self) -> None:
        # Phase 0O.6F: a new advisory on an excepted package, or the same advisory at a new version or a
        # raised severity, is a new finding -- it blocks and needs its own separate review.
        base = next(f for f in grype_findings() if f.id == "CVE-2026-0003")
        for change in ({"id": "CVE-2026-0006"}, {"version": "3.0-2"}, {"severity": "critical"}):
            with self.subTest(change):
                result = evaluate([base, dataclasses.replace(base, **change)], self.exceptions())
                self.assertEqual(result["verdict"], "FAIL")
                self.assertEqual(len(result["blocking"]), 1)
                self.assertEqual(len(result["excepted"]), 1)

    def test_critical_blocks_without_an_exception_and_unused_exceptions_are_reported(self) -> None:
        result = evaluate([f for f in grype_findings() if f.id == "CVE-2026-0001"], self.exceptions())
        self.assertEqual(result["verdict"], "FAIL")
        self.assertEqual(result["unused_exceptions"][0]["id"], "CVE-2026-0003")


if __name__ == "__main__":
    unittest.main()
