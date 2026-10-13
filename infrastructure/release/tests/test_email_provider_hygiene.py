"""ADR 0060 section 28 (E18): the SendGrid integration is development /
non-production only, and its credentials never live in the repository.

- DDEV keeps the Mailpit path (hardened SMTP to 127.0.0.1:1025, plaintext
  allowed only there) and never configures SendGrid, so ordinary local
  development needs no SendGrid credential and cannot reach SendGrid.
- The committed example leaves every SendGrid value empty.
- No tracked file holds a SendGrid-key-shaped value, except the one
  synthetic, obviously fake PHPUnit constant (`phpunitOnly...`).

gitleaks' default `sendgrid-api-token` rule is the scanner-side guard;
this is the structural one for the exact files that matter."""

from __future__ import annotations

import re
import subprocess
import unittest

from lycenza_release.util import REPO_ROOT

SENDGRID_KEY = re.compile(r"SG\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{16,}")
SYNTHETIC_TEST_KEY = "SG.phpunitOnlyKeyId01.phpunitOnlySecretNotARealKey0123456789"


class EmailProviderHygieneTest(unittest.TestCase):
    def test_ddev_email_goes_to_mailpit_and_never_to_sendgrid(self) -> None:
        ddev = (REPO_ROOT / ".ddev" / "config.yaml").read_text(encoding="utf-8")
        for line in ("MAIL_PROVIDER=smtp", "MAIL_SMTP_TLS=none", "MAIL_HOST=127.0.0.1", "MAIL_PORT=1025"):
            self.assertIn(f"- {line}", ddev)
        self.assertNotIn("MAIL_SENDGRID", ddev)
        self.assertNotIn("MAIL_PROVIDER=sendgrid", ddev)
        self.assertNotIn("MAIL_PROVIDER_EVENTS=sendgrid", ddev)

    def test_the_committed_example_leaves_every_sendgrid_value_empty(self) -> None:
        example = (REPO_ROOT / "apps" / "platform" / ".env.example").read_text(encoding="utf-8")
        for name in ("MAIL_SENDGRID_API_KEY", "MAIL_SENDGRID_REGION", "MAIL_SENDGRID_MODE", "MAIL_SENDGRID_TEST_RECIPIENT_DOMAINS"):
            self.assertRegex(example, rf"(?m)^{name}=$")
        self.assertRegex(example, r"(?m)^MAIL_PROVIDER=(none|fake)$")

    def test_no_sendgrid_key_is_committed_except_the_synthetic_test_constant(self) -> None:
        tracked = subprocess.run(["git", "-C", str(REPO_ROOT), "ls-files", "-z"], capture_output=True, check=True).stdout
        found: dict[str, set[str]] = {}
        for rel in filter(None, tracked.decode().split("\0")):
            path = REPO_ROOT / rel
            if not path.is_file() or path.stat().st_size > 2_000_000:
                continue
            try:
                text = path.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                continue
            for match in SENDGRID_KEY.finditer(text):
                found.setdefault(rel, set()).add(match.group(0))
        self.assertEqual(found, {
            "apps/platform/tests/Feature/Email/SendGridEmailProviderTest.php": {SYNTHETIC_TEST_KEY},
            "infrastructure/release/tests/test_email_provider_hygiene.py": {SYNTHETIC_TEST_KEY},
        })


if __name__ == "__main__":
    unittest.main()
