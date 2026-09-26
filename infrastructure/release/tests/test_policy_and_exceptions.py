"""Policy manifest and vulnerability-exception validation (fail closed)."""

from __future__ import annotations

import copy
import json
import unittest

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

    def test_the_repository_exception_file_is_valid_and_empty(self) -> None:
        self.assertEqual(load_exceptions(self.policy, TODAY), [])
        self.assertEqual(json.loads(EXCEPTIONS_FILE.read_text())["exceptions"], [])

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

    def test_a_malformed_file_fails_closed(self) -> None:
        with self.assertRaises(ReleaseError) as caught:
            load_exceptions(self.policy, TODAY, FIXTURES / "exceptions" / "malformed.json")
        self.assertEqual(caught.exception.code, "evidence_malformed")
        with self.assertRaises(ReleaseError) as caught:
            load_exceptions(self.policy, TODAY, FIXTURES / "exceptions" / "expired.json")
        self.assertEqual(caught.exception.code, "exceptions_invalid")


if __name__ == "__main__":
    unittest.main()
