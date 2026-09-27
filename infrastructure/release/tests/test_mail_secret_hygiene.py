"""ADR 0055 (Phase 0O.9A): no real email secret is committed anywhere.

The email suppression HMAC keys, the provider-event secrets and the SMTP
password may appear in committed configuration ONLY as the explicit
development/test placeholders (`dev-local-only-...`, `phpunit-only-...`) or
empty/null (and the literal test marker `canary`). gitleaks' default rules do not know these names and the
source-scan policy forbids custom rules (SupplyChainGuardTest), so this is
the source-side guard; the image-side one is imagescan's secret-shaped env
names and its committed-value canaries. Tests build their canaries at run
time and commit none."""

from __future__ import annotations

import re
import subprocess
import unittest

from lycenza_release.util import REPO_ROOT

NAMES = r"MAIL_SUPPRESSION_HMAC(?:_PREVIOUS)?_KEY|MAIL_PROVIDER_EVENT(?:_PREVIOUS)?_SECRET|MAIL_PASSWORD"
# `NAME=value`, `NAME: value`, `- NAME=value` (compose/DDEV) and phpunit's `name="NAME" value="..."`.
ASSIGNMENT = re.compile(rf'\b({NAMES})\b(?:"[ \t]+value=|[ \t]*[=:][ \t]*)"?([^\s"\'#]*)')
ALLOWED = re.compile(r"^(|null|canary|dev-local-only-[a-z0-9-]+|phpunit-only-[a-z0-9-]+)$")


class MailSecretHygieneTest(unittest.TestCase):
    def test_only_placeholders_are_committed(self) -> None:
        tracked = subprocess.run(["git", "-C", str(REPO_ROOT), "ls-files", "-z"], capture_output=True, check=True).stdout
        offenders: list[str] = []
        seen = 0
        for rel in filter(None, tracked.decode().split("\0")):
            path = REPO_ROOT / rel
            if not path.is_file() or path.stat().st_size > 2_000_000:
                continue
            try:
                text = path.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                continue
            for match in ASSIGNMENT.finditer(text):
                value = match.group(2)
                if value.startswith(("env(", "${", "$", "{")):
                    continue  # a reference, not a value
                seen += 1
                if not ALLOWED.match(value):
                    offenders.append(f"{rel}: {match.group(1)}")  # never the value
        self.assertGreater(seen, 0, "the scan must see the committed placeholders")
        self.assertEqual(offenders, [])


if __name__ == "__main__":
    unittest.main()
