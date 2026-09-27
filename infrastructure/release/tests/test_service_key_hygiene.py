"""ADR 0053 (Phase 0O.7A): no Ed25519 private JWK (a service signing key) is
committed anywhere, except the two DEVELOPMENT keys at their exact files.

gitleaks' default rules do not recognise a JWK, and the source-scan policy
forbids custom rules (SupplyChainGuardTest), so this is the source-side
guard; the image-side one is imagescan's `ed25519_private_jwk` shape.
Tests generate keys at runtime and commit none."""

from __future__ import annotations

import json
import re
import subprocess
import unittest

from lycenza_release.util import REPO_ROOT

PRIVATE_JWK = re.compile(r'\{[^{}]*"crv"\s*:\s*"Ed25519"[^{}]*"d"\s*:\s*"[A-Za-z0-9_-]{43}"[^{}]*\}')
DEVELOPMENT_FILES = {
    "apps/platform/.env.example": {"dev-local-only-platform-1"},
    "services/ai/.env.example": {"dev-local-only-ai-gateway-1"},
    ".ddev/docker-compose.service-keys.yaml": {"dev-local-only-platform-1"},
}


class ServiceKeyHygieneTest(unittest.TestCase):
    def test_only_the_development_keys_are_committed_and_only_where_expected(self) -> None:
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
            for match in PRIVATE_JWK.finditer(text):
                found.setdefault(rel, set()).add(json.loads(match.group(0))["kid"])
        self.assertEqual(found, DEVELOPMENT_FILES)
        for kids in found.values():
            self.assertTrue(all(kid.startswith("dev-local-only-") for kid in kids))


if __name__ == "__main__":
    unittest.main()
