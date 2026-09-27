"""Image config/history/env/label and filesystem canary scans -- including canary PROOF:
every class of secret the scan exists for is planted and must be found."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from lycenza_release.imagescan import CANARIES, apply_allowlist, scan_config, scan_rootfs
from lycenza_release.util import sha256_file

# Canary values are assembled at run time, so the repository source itself
# carries no secret-shaped literal for the source secret scan to flag.
FINGERPRINT_1 = "".join("0123456789ABCDEF"[i % 16] for i in range(40))
FINGERPRINT_2 = FINGERPRINT_1[::-1]
PEM_BEGIN = "-----BEGIN " + "{}PRIVATE KEY-----"
PEM_END = "-----END " + "{}PRIVATE KEY-----"


def config(env=None, labels=None, history=None, cmd=None) -> dict:
    return {"config": {"Env": env or ["PATH=/usr/bin"], "Labels": labels or {}, "Cmd": cmd or ["web"]},
            "history": [{"created_by": h} for h in (history or [])]}


class ConfigScanTest(unittest.TestCase):
    def codes(self, cfg: dict) -> set[str]:
        return {i["code"] for i in scan_config(cfg)}

    def test_a_clean_production_like_config(self) -> None:
        clean = config(env=["APP_ENV=production", "LOG_FORMAT=json", "DB_CONNECTION=pgsql", "PHP_SHA256=" + "a" * 64,
                            f"GPG_KEYS={FINGERPRINT_1} {FINGERPRINT_2}", f"GPG_KEY={FINGERPRINT_1}"],
                       labels={"org.opencontainers.image.revision": "0" * 40},
                       history=[f"ENV GPG_KEY={FINGERPRINT_2}", "RUN /bin/sh -c apt-get update # buildkit"])
        self.assertEqual(scan_config(clean), [])

    def test_canaries_are_found(self) -> None:
        cases = {
            "secret-shaped env": config(env=["DB_PASSWORD=canary-value"]),
            "token env": config(env=["METRICS_SCRAPE_TOKEN=canary"]),
            "api key env": config(env=["AWS_SECRET_ACCESS_KEY=canary"]),
            "a non-fingerprint GPG_KEY": config(env=["GPG_KEY=not-a-fingerprint"]),
            "committed credential in env": config(env=["SOME_SETTING=school_os_app_local_only_password"]),
            "build arg in history": config(history=["RUN |2 REGISTRY_TOKEN=canary OTHER=1 /bin/sh -c make # buildkit"]),
            "env set then cleared (history)": config(history=["ENV REDIS_PASSWORD=canary", "ENV REDIS_PASSWORD="]),
            "demo password in history": config(history=["RUN /bin/sh -c echo Demo1234! # buildkit"]),
            "credential shape in a label": config(labels={"note": "lyc_pat_0123456789abcdefABCDEF"}),
            "secret-shaped label": config(labels={"deploy.api-token": "x"}),
            "app key in cmd": config(cmd=["sh", "-c", "APP_KEY=base64:" + "A" * 44 + " web"]),
        }
        for name, cfg in cases.items():
            with self.subTest(name):
                self.assertTrue(self.codes(cfg), f"{name} not detected")

    def test_the_matched_value_is_never_reported(self) -> None:
        issues = scan_config(config(env=["DB_PASSWORD=canary-value-7f3a"]))
        self.assertNotIn("canary-value-7f3a", repr(issues))


class RootfsScanTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def put(self, path: str, content: bytes) -> Path:
        target = self.root / path.lstrip("/")
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
        return target

    def test_clean_filesystem(self) -> None:
        self.put("/var/www/app/app/Http/Kernel.php", b"<?php // lyc_pat_ prefix constant only\n")
        self.assertEqual(scan_rootfs(self.root, "app"), [])

    def test_canaries_are_found(self) -> None:
        planted = {
            "/var/www/app/.env": b"APP_ENV=production\n",
            "/srv/ai/.env.production": b"x=1\n",
            "/var/www/app/config/leak.php": b"'password' => 'school_os_app_local_only_password'",
            "/var/www/app/storage/seed.txt": b"Demo1234!",
            "/tmp/token.txt": b"Bearer lyc_pk_abcdefghijklmnop0123",
            "/root/key.pem": (PEM_BEGIN.format("RSA ") + "\n" + "MIIEow" * 20 + "\n" + PEM_END.format("RSA ") + "\n").encode(),
            "/etc/app.env": b"APP_KEY=base64:" + b"B" * 44,
        }
        for path, content in planted.items():
            self.put(path, content)
        found = {i["where"] for i in scan_rootfs(self.root, "app")}
        self.assertEqual(found, set(planted), "every planted canary must be reported, and only those")

    def test_development_values_only_inside_the_guard_that_refuses_them(self) -> None:
        guard = "/var/www/app/app/Support/Configuration/ProductionConfigurationGuard.php"
        self.put(guard, b"const DEV = 'dev-local-only-token'; const S = ['school_os_secret'];")
        self.assertEqual(scan_rootfs(self.root, "app"), [])
        self.put(guard, b"'Demo1234!'")
        self.assertEqual([i["where"] for i in scan_rootfs(self.root, "app")], [guard], "the demo password is not guard-allowed")
        self.assertTrue(scan_rootfs(self.root, "ai-gateway"), "the app guard path is not allowed in the Gateway image")

    def test_service_private_keys_never_pass(self) -> None:
        """ADR 0053: an Ed25519 private JWK (dev, test or real) is reported anywhere, and
        the committed development seeds even inside the guard; public keys are fine."""
        import base64
        import os

        seed = base64.urlsafe_b64encode(os.urandom(32)).rstrip(b"=")
        public = base64.urlsafe_b64encode(os.urandom(32)).rstrip(b"=")
        jwk = b'{"kty":"OKP","crv":"Ed25519","kid":"platform-1","x":"' + public + b'","d":"' + seed + b'","created":"2026-10-01"}'
        self.put("/srv/ai/app/keys.json", jwk)
        self.put("/srv/ai/app/ring.json", jwk.replace(b',"d":"' + seed + b'"', b""))
        self.assertEqual([(i["code"], i["where"]) for i in scan_rootfs(self.root, "ai-gateway")], [("ed25519_private_jwk", "/srv/ai/app/keys.json")])

        (self.root / "srv/ai/app/keys.json").unlink()
        dev_seeds = [c for c in CANARIES if not c.startswith(("dev-", "Demo", "school_os", "minio"))]
        self.assertEqual(len(dev_seeds), 2)
        guard = "/var/www/app/app/Support/Configuration/ProductionConfigurationGuard.php"
        self.put(guard, dev_seeds[0].encode())
        self.assertEqual([i["where"] for i in scan_rootfs(self.root, "app")], [guard], "a dev seed is never guard-allowed")

    def test_the_allowlist_is_exact_path_code_and_content(self) -> None:
        pem = (PEM_BEGIN.format("") + "\n" + "MIIBVA" * 20 + "\n").encode()
        target = self.put("/usr/lib/libself-test.so", pem)
        issues = scan_rootfs(self.root, "app")
        entry = {"image": "app", "code": "private_key_material", "path": "/usr/lib/libself-test.so", "sha256": sha256_file(target), "reason": "test"}
        remaining, allowed = apply_allowlist(issues, [entry], self.root, "app")
        self.assertEqual((remaining, len(allowed)), ([], 1))
        self.assertEqual(apply_allowlist(issues, [entry], self.root, "ai-gateway")[0], issues, "other image")
        target.write_bytes(pem + b"changed")
        self.assertEqual(apply_allowlist(issues, [entry], self.root, "app")[0], issues, "changed content is scanned afresh")


if __name__ == "__main__":
    unittest.main()
