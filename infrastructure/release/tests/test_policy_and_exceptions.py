"""Policy manifest and vulnerability-exception validation (fail closed)."""

from __future__ import annotations

import copy
import json
import tempfile
import unittest
from pathlib import Path

from lycenza_release.exceptions import (
    EXCEPTIONS_FILE,
    load_exceptions,
    validate_exceptions,
)
from lycenza_release.policy import POLICY_FILE, load_policy, validate_policy
from lycenza_release.schema import SchemaError, validate
from lycenza_release.util import ReleaseError
from support import FIXTURES, TODAY


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
        """Phase 0O.6F: OWNER-0O6E-2026-09-26, activated exactly -- 12 advisories, exact per-package statuses, no broadening."""
        util_linux = {"bsdutils", "libblkid1", "liblastlog2-2", "libmount1", "libsmartcols1", "libuuid1", "login", "mount", "util-linux"}

        def statuses(accepted: set[str], others: set[str], other: str = "not_affected") -> dict[str, dict[str, str]]:
            both = {**{p: "accepted_risk" for p in accepted}, **{p: other for p in others}}
            return {"app": both, "ai-gateway": both}

        ncurses = {"app": {"libtinfo6", "ncurses-base"}, "ai-gateway": {"libtinfo6", "ncurses-base", "libncursesw6"}}
        decision = {  # advisory: (days, conditional, {image: {package: exact status}})
            "CVE-2026-76642": (14, True, statuses({"mount", "libmount1"}, util_linux - {"mount", "libmount1"})),
            "CVE-2026-78408": (30, False, statuses({"util-linux"}, util_linux - {"util-linux"})),
            "CVE-2026-78409": (14, True, statuses({"mount", "libmount1"}, util_linux - {"mount", "libmount1"})),
            "CVE-2026-78410": (14, True, statuses({"mount", "libmount1"}, util_linux - {"mount", "libmount1"})),
            "CVE-2026-19499": (30, False, statuses({"libc6"}, {"libc-bin"})),
            "CVE-2026-5435": (30, False, statuses({"libc6"}, {"libc-bin"})),
            "CVE-2026-54369": (30, True, statuses({"libacl1"}, set())),
            "CVE-2026-54370": (30, True, statuses({"libacl1"}, set())),
            "CVE-2026-82560": (30, False, statuses(set(), {"perl-base"})),
            "CVE-2026-9538": (30, False, statuses(set(), {"perl-base"})),
            "CVE-2025-69720": (30, False, {image: statuses({"ncurses-bin"}, ncurses[image])[image] for image in ncurses}),
            "CVE-2026-85091": (30, False, statuses(set(), {"zlib1g"}, "false_positive")),
        }
        document = json.loads(EXCEPTIONS_FILE.read_text())
        valid = load_exceptions(self.policy, TODAY)
        self.assertEqual(len(valid), len(document["exceptions"]))
        self.assertEqual(list(document["approvals"]), ["OWNER-0O6E-2026-09-26"])
        approval = document["approvals"]["OWNER-0O6E-2026-09-26"]
        self.assertEqual(approval["record"], "docs/security/release-remediation/0O.6E-owner-security-decision.md")
        self.assertEqual(approval["reviewed_digests"], {
            "app": "sha256:11a5a7612b32ffb47d036c30fcdd0f23bf5e7e97b95f516f718c9dee342d75de",
            "ai-gateway": "sha256:d110ed022f3eb066e853821b8e2b254a642469cc0600bc782bf9e72ce1d006ac"})
        self.assertEqual(set(approval["advisories"]), set(decision), "no other advisory is approved")

        seen = set()
        for e in valid:
            days, conditional, packages = decision[e.id]
            with self.subTest(e.id, image=e.image, package=e.package):
                self.assertEqual(e.status, packages[e.image][e.package])
                self.assertEqual((e.severity, e.approved_by), ("high", "OWNER-0O6E-2026-09-26"))
                self.assertEqual((e.created.isoformat(), (e.expires - e.created).days), ("2026-09-26", days))
                self.assertEqual(e.conditions, ("runtime-hardening",) if conditional else ())
            seen.add((e.id, e.image, e.package))
        expected_records = {(a, image, p) for a, (_, _, packages) in decision.items() for image in packages for p in packages[image]}
        self.assertEqual(seen, expected_records)
        self.assertEqual(sum(1 for e in valid if e.image == "app"), 48)
        self.assertEqual(sum(1 for e in valid if e.image == "ai-gateway"), 49)

    def test_the_activated_records_expire_and_then_fail_every_verification(self) -> None:
        from datetime import date
        self.assertEqual(len(load_exceptions(self.policy, date(2026, 10, 9))), 97)
        for day in (date(2026, 10, 10), date(2026, 10, 26)):
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
