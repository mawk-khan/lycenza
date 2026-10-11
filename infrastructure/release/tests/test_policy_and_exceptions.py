"""Policy manifest and vulnerability-exception validation (fail closed)."""

from __future__ import annotations

import copy
import json
from collections import Counter
from datetime import date
import tempfile
import unittest
from pathlib import Path

from types import SimpleNamespace
from unittest import mock

from lycenza_release import verify
from lycenza_release.evaluate import evaluate
from lycenza_release.exceptions import (
    EXCEPTIONS_FILE,
    load_exceptions,
    validate_exceptions,
)
from lycenza_release.findings import Finding
from lycenza_release.policy import POLICY_FILE, load_policy, validate_policy
from lycenza_release.schema import SchemaError, validate
from lycenza_release.util import ReleaseError
from support import FIXTURES, TODAY

DECISION = "OWNER-0O-E16-2026-10-11"
SUPERSEDED = ("OWNER-0O6E-2026-09-26", "OWNER-0O-E16-2026-09-29")
CONDITIONAL = {"CVE-2026-76642", "CVE-2026-78409", "CVE-2026-78410", "CVE-2026-54369", "CVE-2026-54370"}


class SchemaValidatorTest(unittest.TestCase):
    def test_unsupported_keywords_fail_closed(self) -> None:
        with self.assertRaises(SchemaError):
            validate({}, {"type": "object", "patternProperties": {}})
        with self.assertRaises(SchemaError):
            validate("x", {"type": "string", "format": "email"})

    def test_basic_keywords(self) -> None:
        schema = {"type": "object", "additionalProperties": False, "required": ["a"],
                  "properties": {"a": {"type": "string", "pattern": "^x+$"}, "b": {"type": "integer", "maximum": 3}}}
        self.assertEqual(validate({"a": "xx"}, schema), [])
        self.assertTrue(validate({"a": "y"}, schema))
        self.assertTrue(validate({"a": "x", "c": 1}, schema))
        self.assertTrue(validate({"a": "x", "b": 4}, schema))
        self.assertTrue(validate({"a": "x", "b": True}, schema), "a boolean is not an integer")
        self.assertTrue(validate({}, schema))


class PolicyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.policy = json.loads(POLICY_FILE.read_text())

    def test_the_repository_policy_is_valid(self) -> None:
        self.assertEqual(validate_policy(self.policy), [])
        self.assertEqual(load_policy()["images"]["app"]["name"], "lycenza-app")

    def test_no_signing_identity_is_configured(self) -> None:
        signing = self.policy["signing"]
        self.assertEqual(signing["custody"], "unconfigured")
        self.assertEqual([signing[k] for k in ("public_key_ref", "certificate_identity", "certificate_oidc_issuer")], ["", "", ""])

    def test_every_tool_is_pinned_by_digest(self) -> None:
        for name in self.policy["tools"]:
            bad = copy.deepcopy(self.policy)
            bad["tools"][name] = bad["tools"][name].split("@")[0]
            self.assertTrue(validate_policy(bad), f"{name} accepted without a digest")
        latest = copy.deepcopy(self.policy)
        latest["tools"]["grype"] = "anchore/grype:latest"
        self.assertTrue(validate_policy(latest))

    def test_thresholds_cannot_be_weakened(self) -> None:
        for path, value in ((("vulnerability_policy", "blocking", "critical"), "never"),
                            (("vulnerability_policy", "scanner_db_max_age_hours"), 48),
                            (("vulnerability_policy", "exception_max_days", "high"), 60),
                            (("required_evidence", "complete_regression"), False),
                            (("states", "repository_reachable"), ["BUILT", "VERIFIED", "PROMOTED"])):
            bad = copy.deepcopy(self.policy)
            target = bad
            for key in path[:-1]:
                target = target[key]
            target[path[-1]] = value
            self.assertTrue(validate_policy(bad), f"{'.'.join(path)} = {value!r} accepted")

    def test_custody_shapes(self) -> None:
        keyless = copy.deepcopy(self.policy)
        keyless["signing"].update(custody="keyless", certificate_identity="https://example.invalid/wf")
        self.assertTrue(validate_policy(keyless), "keyless without an issuer")
        keyless["signing"]["certificate_oidc_issuer"] = "https://token.actions.githubusercontent.com"
        self.assertEqual(validate_policy(keyless), [])
        external = copy.deepcopy(self.policy)
        external["signing"]["custody"] = "external-key"
        self.assertTrue(validate_policy(external), "external key without a reference")
        unconfigured = copy.deepcopy(self.policy)
        unconfigured["signing"]["public_key_ref"] = "awskms:///alias/x"
        self.assertTrue(validate_policy(unconfigured), "an unconfigured custody naming a key")

    def test_unknown_fields_are_refused(self) -> None:
        bad = copy.deepcopy(self.policy)
        bad["registry"] = "registry.example.invalid"
        self.assertTrue(validate_policy(bad))


class ExceptionsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.policy = load_policy()

    def check(self, name: str) -> list[str]:
        return validate_exceptions(json.loads((FIXTURES / "exceptions" / f"{name}.json").read_text()), self.policy, TODAY)[1]

    def test_the_repository_exception_file_is_exactly_the_owner_decision(self) -> None:
        """Phase 0O / E16-D1: OWNER-0O-E16-2026-10-11 (fresh scan after E16-R1), activated exactly -- 16 advisories, exact per-package statuses, no broadening."""
        util_linux = {"bsdutils", "libblkid1", "liblastlog2-2", "libmount1", "libsmartcols1", "libuuid1", "login", "mount", "util-linux"}

        def statuses(accepted: set[str], others: set[str], other: str = "not_affected") -> dict[str, dict[str, str]]:
            both = {**{p: "accepted_risk" for p in accepted}, **{p: other for p in others}}
            return {"app": both, "ai-gateway": both}

        ncurses = {"app": {"libtinfo6", "ncurses-base"}, "ai-gateway": {"libtinfo6", "ncurses-base", "libncursesw6"}}
        decision = {  # advisory: (days, conditional, {image: {package: exact status}})
            "CVE-2026-76642": (30, True, statuses({"mount", "libmount1"}, util_linux - {"mount", "libmount1"})),
            "CVE-2026-78408": (30, False, statuses({"util-linux"}, util_linux - {"util-linux"})),
            "CVE-2026-78409": (30, True, statuses({"mount", "libmount1"}, util_linux - {"mount", "libmount1"})),
            "CVE-2026-78410": (30, True, statuses({"mount", "libmount1"}, util_linux - {"mount", "libmount1"})),
            "CVE-2026-19499": (30, False, statuses({"libc6"}, {"libc-bin"})),
            "CVE-2026-5435": (30, False, statuses({"libc6"}, {"libc-bin"})),
            "CVE-2026-54369": (30, True, statuses({"libacl1"}, set())),
            "CVE-2026-54370": (30, True, statuses({"libacl1"}, set())),
            "CVE-2026-82560": (30, False, statuses(set(), {"perl-base"})),
            "CVE-2026-9538": (30, False, statuses(set(), {"perl-base"})),
            "CVE-2025-69720": (30, False, {image: statuses({"ncurses-bin"}, ncurses[image])[image] for image in ncurses}),
            "CVE-2026-85091": (30, False, statuses(set(), {"zlib1g"}, "false_positive")),
            # E16-D1: accepted_risk only where the vulnerable code is (RELEASE-QUALIFICATION.md).
            "CVE-2026-107778": (30, False, {"app": {"libkrb5-3": "accepted_risk", "libgssapi-krb5-2": "accepted_risk",
                                                    "libk5crypto3": "not_affected", "libkrb5support0": "not_affected"}}),
            "CVE-2026-107161": (30, False, {"app": {"libsasl2-2": "not_affected", "libsasl2-modules-db": "not_affected"}}),
            "CVE-2026-102010": (30, False, statuses({"libstdc++6"}, {"libgcc-s1", "gcc-14-base"})),
            "CVE-2026-95619": (30, False, statuses({"libstdc++6"}, {"libgcc-s1", "gcc-14-base"})),
        }
        document = json.loads(EXCEPTIONS_FILE.read_text())
        valid = load_exceptions(self.policy, date(2026, 10, 11))  # the decision date (TODAY is an earlier fixture date)
        self.assertEqual(len(valid), len(document["exceptions"]))
        self.assertEqual(list(document["approvals"]), [DECISION], "only the current decision is active")
        approval = document["approvals"][DECISION]
        self.assertEqual(approval["record"], "docs/security/release-remediation/0O-E16-2026-10-11-owner-security-decision.md")
        self.assertEqual(approval["decided"], "2026-10-11")
        self.assertEqual(approval["reviewed_digests"], {
            "app": "sha256:17ba265968b539f8a73e0163f766563ec319ddb216184c55592b320dc4ad4db8",
            "ai-gateway": "sha256:6347ada75c76eae600cccda7199bca31d3351ace06fc5c5f7eb6ee710a62fcba"})
        self.assertEqual(set(approval["advisories"]), set(decision), "no other advisory is approved")

        seen = set()
        for e in valid:
            days, conditional, packages = decision[e.id]
            with self.subTest(e.id, image=e.image, package=e.package):
                self.assertEqual(e.status, packages[e.image][e.package])
                self.assertEqual((e.severity, e.approved_by), ("high", DECISION))
                self.assertEqual((e.created.isoformat(), e.expires.isoformat(), (e.expires - e.created).days), ("2026-10-11", "2026-11-10", days))
                self.assertEqual(e.conditions, ("runtime-hardening",) if conditional else ())
            seen.add((e.id, e.image, e.package))
        expected_records = {(a, image, p) for a, (_, _, packages) in decision.items() for image in packages for p in packages[image]}
        self.assertEqual(seen, expected_records)
        self.assertEqual(sum(1 for e in valid if e.image == "app"), 60)
        self.assertEqual(sum(1 for e in valid if e.image == "ai-gateway"), 55)
        self.assertEqual(Counter(e.status for e in valid), {"accepted_risk": 30, "not_affected": 83, "false_positive": 2})

    def test_the_activated_records_expire_and_then_fail_every_verification(self) -> None:
        """One common expiry: valid 2026-10-11 through 2026-11-09, and the WHOLE file fails from 2026-11-10 (and before its creation)."""
        self.assertEqual(len(load_exceptions(self.policy, date(2026, 10, 11))), 115)
        self.assertEqual(len(load_exceptions(self.policy, date(2026, 11, 9))), 115)
        for day in (date(2026, 10, 10), date(2026, 11, 10), date(2026, 11, 15)):
            with self.subTest(day), self.assertRaises(ReleaseError) as caught:
                load_exceptions(self.policy, day)
            self.assertEqual(caught.exception.code, "exceptions_invalid")

    def test_a_valid_exception(self) -> None:
        valid, errors = validate_exceptions(json.loads((FIXTURES / "exceptions" / "valid.json").read_text()), self.policy, TODAY)
        self.assertEqual(errors, [])
        self.assertEqual(valid[0].id, "CVE-2026-0003")

    def test_invalid_exceptions_fail(self) -> None:
        for name in ("expired", "too-long-high", "too-long-medium", "wildcard-id", "wildcard-package", "version-range",
                     "unapproved", "empty-approval", "bad-date", "bad-status", "future-created", "unknown-field", "duplicate"):
            with self.subTest(name):
                self.assertTrue(self.check(name), f"{name} was accepted")

    def test_any_error_invalidates_the_whole_file(self) -> None:
        document = json.loads((FIXTURES / "exceptions" / "valid.json").read_text())
        expired = copy.deepcopy(document["exceptions"][0])
        expired.update(id="CVE-2026-9999", created="2026-08-01", expires="2026-08-20")
        document["exceptions"].append(expired)
        valid, errors = validate_exceptions(document, self.policy, TODAY)
        self.assertEqual(valid, [])
        self.assertTrue(errors)

    # --- Phase 0O.6F: approval linkage ----------------------------------------------

    def valid(self) -> dict:
        return json.loads((FIXTURES / "exceptions" / "valid.json").read_text())

    def errors(self, document: dict, repo: Path | None = None) -> list[str]:
        return validate_exceptions(document, self.policy, TODAY, **({"repo": repo} if repo else {}))[1]

    def assertRefused(self, document: dict, fragment: str, repo: Path | None = None) -> None:
        errors = self.errors(document, repo)
        self.assertTrue(any(fragment in e for e in errors), f"expected {fragment!r} in {errors}")

    def test_every_exception_links_to_a_recorded_approval(self) -> None:
        unlinked = self.valid()
        unlinked["exceptions"][0]["approved_by"] = "SEC-REVIEW-0002"
        self.assertRefused(unlinked, "approval reference not recorded")
        no_approvals = self.valid()
        del no_approvals["approvals"]
        self.assertRefused(no_approvals, "approval reference not recorded")
        malformed = self.valid()
        malformed["approvals"]["sec review"] = malformed["approvals"]["SEC-REVIEW-0001"]
        self.assertRefused(malformed, "malformed approval reference")

    def test_the_decision_record_must_exist_and_name_the_approval_and_each_advisory(self) -> None:
        for record in ("docs/missing.md", "../outside.md", "/etc/passwd.md"):
            with self.subTest(record):
                document = self.valid()
                document["approvals"]["SEC-REVIEW-0001"]["record"] = record
                self.assertTrue(self.errors(document))
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)
            record = repo / "infrastructure/release/tests/fixtures/exceptions/SEC-REVIEW-0001.md"
            record.parent.mkdir(parents=True)
            original = (FIXTURES / "exceptions" / "SEC-REVIEW-0001.md").read_text()
            record.write_text(original)
            self.assertEqual(self.errors(self.valid(), repo), [])
            record.write_text(original.replace("SEC-REVIEW-0001", "SEC-REVIEW-9999"))
            self.assertRefused(self.valid(), "does not name the approval", repo)
            record.write_text(original.replace("CVE-2026-0002", "an advisory"))
            self.assertRefused(self.valid(), "does not name CVE-2026-0002", repo)

    def test_an_exception_cannot_go_beyond_its_approval(self) -> None:
        for change, fragment in (({"id": "CVE-2026-0004"}, "is not approved by"),
                                 ({"package": "libother"}, "is not approved for"),
                                 ({"image": "ai-gateway", "package": "libexample"}, "is not approved for"),
                                 ({"created": "2026-07-31", "expires": "2026-08-30"}, "created before the approval"),
                                 ({"conditions": ["runtime-hardening"]}, "conditions differ")):
            with self.subTest(change):
                document = self.valid()
                document["exceptions"][0].update(change)
                self.assertRefused(document, fragment)

    def test_the_exact_per_package_status_is_enforced(self) -> None:
        for status in ("not_affected", "false_positive"):
            with self.subTest(status):
                document = self.valid()
                document["exceptions"][0]["status"] = status
                self.assertRefused(document, f"status {status} differs from the approved accepted_risk")

    def test_the_approved_duration_is_enforced_below_the_policy_maximum(self) -> None:
        document = self.valid()
        document["approvals"]["SEC-REVIEW-0001"]["advisories"]["CVE-2026-0003"]["max_days"] = 14
        document["exceptions"][0].update(created="2026-09-20", expires="2026-10-05")
        self.assertRefused(document, "exceeds the approved 14 days")
        document["exceptions"][0]["expires"] = "2026-10-04"
        self.assertEqual(self.errors(document), [])

    def test_a_conditional_approval_needs_the_condition_on_the_record(self) -> None:
        document = self.valid()
        document["approvals"]["SEC-REVIEW-0001"]["advisories"]["CVE-2026-0003"]["conditions"] = ["runtime-hardening"]
        self.assertRefused(document, "conditions differ")
        document["exceptions"][0]["conditions"] = ["runtime-hardening"]
        valid, errors = validate_exceptions(document, self.policy, TODAY)
        self.assertEqual(errors, [])
        self.assertEqual(valid[0].conditions, ("runtime-hardening",))
        document["exceptions"][0]["conditions"] = ["root-allowed"]
        self.assertTrue(self.errors(document), "an unknown condition is refused by the schema")

    def test_expiry_is_exclusive_and_fails_the_whole_file(self) -> None:
        document = self.valid()
        document["exceptions"][0].update(created="2026-09-20", expires=TODAY.isoformat())
        self.assertRefused(document, "expired")
        document["exceptions"][0]["expires"] = "2026-09-27"
        self.assertEqual(self.errors(document), [])

    def test_a_malformed_file_fails_closed(self) -> None:
        with self.assertRaises(ReleaseError) as caught:
            load_exceptions(self.policy, TODAY, FIXTURES / "exceptions" / "malformed.json")
        self.assertEqual(caught.exception.code, "evidence_malformed")
        with self.assertRaises(ReleaseError) as caught:
            load_exceptions(self.policy, TODAY, FIXTURES / "exceptions" / "expired.json")
        self.assertEqual(caught.exception.code, "exceptions_invalid")


if __name__ == "__main__":
    unittest.main()


class CurrentDecisionEngineTest(unittest.TestCase):
    """Phase 0O / E16-D1: the engine applied to the REAL OWNER-0O-E16-2026-10-11 records (not fixtures)."""

    def setUp(self) -> None:
        self.policy = load_policy()
        self.document = json.loads(EXCEPTIONS_FILE.read_text())
        self.valid = load_exceptions(self.policy, date(2026, 11, 9))

    def findings(self, **change: str) -> list[Finding]:
        return [Finding("grype", e.image, e.id, e.package, e.version, "high", "not-fixed") if not change
                else Finding("grype", e.image, e.id, e.package, e.version, change.get("severity", "high"), change.get("fix_state", "not-fixed"))
                for e in self.valid]

    def test_the_reviewed_residual_set_passes_exactly_with_no_unused_record(self) -> None:
        result = evaluate(self.findings(), self.valid)
        self.assertEqual((result["verdict"], len(result["blocking"]), len(result["excepted"]), result["unused_exceptions"]), ("PASS", 0, 115, []))
        self.assertEqual(Counter(r["image"] for r in result["excepted"]), {"app": 60, "ai-gateway": 55})

    def test_no_critical_exception_is_active_and_every_record_is_a_high_without_a_fix(self) -> None:
        """E16-D1: the decision governs only High findings with no fix; a Critical finding on any covered package blocks."""
        self.assertEqual({e.severity for e in self.valid}, {"high"})
        self.assertEqual({r["severity"] for r in self.document["exceptions"]}, {"high"})
        critical = evaluate(self.findings(severity="critical"), self.valid)
        self.assertEqual((critical["verdict"], len(critical["excepted"])), ("FAIL", 0))

    def test_no_superseded_record_remains_and_the_superseded_approval_cannot_be_used(self) -> None:
        for superseded in SUPERSEDED:
            with self.subTest(superseded):
                self.assertNotIn(superseded, json.dumps(self.document))
                document = copy.deepcopy(self.document)
                document["exceptions"][0]["approved_by"] = superseded
                errors = validate_exceptions(document, self.policy, date(2026, 10, 12))[1]
                self.assertIn("$.exceptions[0]: approval reference not recorded in approvals", errors)

    def test_a_fix_a_version_change_or_a_severity_change_ends_coverage(self) -> None:
        fixed = evaluate(self.findings(fix_state="fixed"), self.valid)
        self.assertEqual({r["reason"] for r in fixed["blocking"]}, {"high_fix_available"})
        self.assertEqual(len(fixed["blocking"]), 115)
        critical = evaluate(self.findings(severity="critical"), self.valid)
        self.assertEqual({r["reason"] for r in critical["blocking"]}, {"critical"})
        e = self.valid[0]
        moved = evaluate([Finding("grype", e.image, e.id, e.package, e.version + "+deb13u9", "high", "not-fixed")], self.valid)
        self.assertEqual([r["reason"] for r in moved["blocking"]], ["high_requires_exception"])

    def test_an_unapproved_advisory_or_a_new_high_or_critical_blocks(self) -> None:
        e = self.valid[0]
        extra = [Finding("grype", e.image, "CVE-2026-99999", e.package, e.version, "high", "not-fixed"),
                 Finding("grype", "app", "CVE-2026-99998", "libnew1", "1.0-1", "critical", "not-fixed")]
        result = evaluate(self.findings() + extra, self.valid)
        self.assertEqual(sorted(r["id"] for r in result["blocking"]), ["CVE-2026-99998", "CVE-2026-99999"])
        document = copy.deepcopy(self.document)
        document["exceptions"][0]["id"] = "CVE-2026-99999"
        self.assertTrue(any("is not approved by" in err for err in validate_exceptions(document, self.policy, date(2026, 10, 12))[1]))

    def test_the_reviewed_classifications_cannot_be_broadened(self) -> None:
        document = copy.deepcopy(self.document)
        index = next(i for i, r in enumerate(document["exceptions"]) if r["status"] == "not_affected")
        document["exceptions"][index]["status"] = "accepted_risk"
        self.assertTrue(any("differs from the approved" in err for err in validate_exceptions(document, self.policy, date(2026, 10, 12))[1]))
        document = copy.deepcopy(self.document)
        document["exceptions"][0]["expires"] = "2026-11-11"
        self.assertTrue(validate_exceptions(document, self.policy, date(2026, 10, 12))[1], "no record may extend past 2026-11-10")

    def test_runtime_hardening_records_are_exactly_the_conditional_advisories_and_are_withdrawn_without_proof(self) -> None:
        self.assertEqual({e.id for e in self.valid if e.conditions}, CONDITIONAL)
        self.assertTrue(all(e.conditions == ("runtime-hardening",) for e in self.valid if e.id in CONDITIONAL))

        def refuse(_ctx: object) -> None:
            raise ReleaseError("runtime_hardening_evidence_missing")

        for image in ("app", "ai-gateway"):
            ctx = SimpleNamespace(image=image, exceptions=list(self.valid))
            with self.subTest(image), mock.patch.dict(verify.CONDITION_EVIDENCE, {"runtime-hardening": refuse}), self.assertRaises(ReleaseError):
                verify._conditions(ctx)
            remaining = [e for e in ctx.exceptions if e.image == image]
            self.assertFalse(any(e.conditions for e in remaining), "the conditional records are withdrawn")
            result = evaluate([f for f in self.findings() if f.image == image], ctx.exceptions)
            self.assertEqual({r["id"] for r in result["blocking"]}, CONDITIONAL)


class CriticalNeverExceptionEligibleTest(unittest.TestCase):
    """E16-P1 (ADR 0052 section 3.7 amendment): no exception record can make a Critical pass -- at the
    validator (the file) and, independently, at the one evaluator (every route: audit, rescan, verify, all)."""

    DAY = date(2026, 10, 12)  # inside the live decision's window

    def setUp(self) -> None:
        self.policy = load_policy()
        self.document = json.loads(EXCEPTIONS_FILE.read_text())

    @staticmethod
    def record(status: str = "accepted_risk", severity: str = "critical", created: date = date(2026, 10, 11),
               expires: date = date(2026, 11, 10)):
        from lycenza_release.exceptions import VulnerabilityException
        return VulnerabilityException(id="CVE-2026-99990", status=status, image="app", package="libcrit1", version="1.0-1",
                                      severity=severity, approved_by=DECISION, created=created, expires=expires)

    @staticmethod
    def finding(severity: str = "critical", fix_state: str = "not-fixed") -> Finding:
        return Finding("grype", "app", "CVE-2026-99990", "libcrit1", "1.0-1", severity, fix_state)

    def test_a_critical_without_an_exception_blocks(self) -> None:
        result = evaluate([self.finding()], [])
        self.assertEqual((result["verdict"], [r["reason"] for r in result["blocking"]]), ("FAIL", ["critical"]))

    def test_a_critical_with_an_exactly_matching_exception_still_blocks_and_the_record_is_named(self) -> None:
        """Whatever the status, fix state, dates or approval: refused, named, never excepted."""
        for status in ("accepted_risk", "not_affected", "false_positive"):
            for fix_state in ("not-fixed", "wont-fix", "fixed"):
                for created, expires in ((date(2026, 10, 11), date(2026, 11, 10)), (date(2026, 12, 1), date(2026, 12, 31))):
                    with self.subTest(status=status, fix_state=fix_state, created=created):
                        result = evaluate([self.finding(fix_state=fix_state)], [self.record(status, created=created, expires=expires)])
                        self.assertEqual(result["verdict"], "FAIL")
                        self.assertEqual(result["excepted"], [])
                        self.assertEqual([(r["reason"], r["refused_exception"]) for r in result["blocking"]],
                                         [("critical", "CVE-2026-99990")])
                        self.assertEqual(result["unused_exceptions"], [], "the refused record is reported on the finding")

    def test_the_validator_refuses_any_critical_record_even_with_a_valid_window_and_owner_approval(self) -> None:
        for status in ("accepted_risk", "not_affected", "false_positive"):
            with self.subTest(status):
                document = copy.deepcopy(self.document)
                document["exceptions"][0].update(severity="critical", status=status)
                valid, errors = validate_exceptions(document, self.policy, self.DAY)
                self.assertEqual(valid, [], "any error invalidates the whole file")
                self.assertIn("$.exceptions[0]: a Critical is never exception-eligible (ADR 0052 section 3.7, E16-P1)", errors)
        document = copy.deepcopy(self.document)
        document["exceptions"][0]["severity"] = "critical"
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "exceptions.json"
            path.write_text(json.dumps(document))
            with self.assertRaises(ReleaseError) as caught:
                load_exceptions(self.policy, self.DAY, path)
        self.assertEqual(caught.exception.code, "exceptions_invalid")

    def test_the_policy_declares_no_critical_exception_maximum(self) -> None:
        self.assertEqual(self.policy["vulnerability_policy"]["blocking"]["critical"], "always")
        self.assertNotIn("critical", self.policy["vulnerability_policy"]["exception_max_days"])
        self.assertNotIn("critical", self.policy["vulnerability_policy"]["exception_required"])
        reintroduced = copy.deepcopy(self.policy)
        reintroduced["vulnerability_policy"]["exception_max_days"]["critical"] = 30
        self.assertTrue(validate_policy(reintroduced), "a Critical exception maximum may not be reintroduced")

    def test_high_rules_are_unchanged(self) -> None:
        """A High without a fix may be excepted; a High with a fix and an expired High exception still fail."""
        high = self.record("accepted_risk", severity="high")
        self.assertEqual(evaluate([self.finding("high")], [high])["verdict"], "PASS")
        fixed = evaluate([self.finding("high", "fixed")], [high])
        self.assertEqual([(r["reason"], r["superseded_exception"]) for r in fixed["blocking"]], [("high_fix_available", "CVE-2026-99990")])
        document = copy.deepcopy(self.document)
        document["exceptions"][0]["expires"] = "2026-10-12"
        self.assertIn("$.exceptions[0]: expired", validate_exceptions(document, self.policy, self.DAY)[1])

    def test_the_live_decision_is_unaffected(self) -> None:
        valid = load_exceptions(self.policy, self.DAY)
        self.assertEqual((len(valid), {e.severity for e in valid}, {e.approved_by for e in valid}), (115, {"high"}, {DECISION}))
