"""Canary PROOF against real tools (Docker required; reported as skipped, never as
passed, without it): a real image built with planted secrets is caught by the
history/env/label scan, and the pinned gitleaks with the repository's exact-match
allowlist still finds a real-looking secret planted in an allowlisted file."""

from __future__ import annotations

import json
import secrets
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

from lycenza_release.imagescan import config_from_docker, scan_config
from lycenza_release.policy import load_policy
from lycenza_release.tools import docker_run
from lycenza_release.util import REPO_ROOT
from support import docker_available


@unittest.skipUnless(docker_available(), "Docker is required (reported as SKIPPED, not passed)")
class CanaryProofTest(unittest.TestCase):
    def setUp(self) -> None:
        self.policy = load_policy()
        self.tmp = Path(tempfile.mkdtemp(prefix="lycenza-canary-"))

    def tearDown(self) -> None:
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_history_env_and_label_canaries_in_a_real_image(self) -> None:
        tag = f"lycenza-canary-proof:{secrets.token_hex(4)}"
        canary = "canary-" + secrets.token_hex(8)
        (self.tmp / "Dockerfile").write_text(
            f"FROM {self.policy['tools']['python']}\n"
            "ARG DEPLOY_TOKEN\n"
            "RUN echo building > /tmp/x\n"
            f"ENV REDIS_PASSWORD={canary}\n"
            "ENV REDIS_PASSWORD=\n"
            f"LABEL build.note=\"lyc_pat_{secrets.token_hex(20)}\"\n"
        )
        build = subprocess.run(["docker", "buildx", "build", "--builder", "default", "--load", "--build-arg", f"DEPLOY_TOKEN={canary}",
                                "-t", tag, str(self.tmp)], capture_output=True, check=False)
        self.assertEqual(build.returncode, 0, build.stderr.decode()[-500:])
        try:
            issues = scan_config(config_from_docker(tag))
        finally:
            subprocess.run(["docker", "image", "rm", "-f", tag], capture_output=True, check=False)
        codes = {(i["code"], i["where"].split(":")[0]) for i in issues}
        self.assertIn(("secret_build_arg", "history"), codes, issues)
        self.assertIn(("secret_shaped_env", "history"), codes, issues)
        self.assertIn(("application_credential", "label"), codes, issues)
        self.assertNotIn(canary, json.dumps(issues), "the scan never echoes the value")

    def test_the_source_allowlist_is_exact_not_a_file_exemption(self) -> None:
        allowlisted = self.tmp / "apps/platform/app/Models/ApiClient.php"
        allowlisted.parent.mkdir(parents=True)
        fake = "AKIA" + "".join(secrets.choice("ABCDEFGHIJKLMNOPQRSTUVWXYZ234567") for _ in range(16))
        trait = "Generates" + "UuidV7"  # the allowlisted value, assembled so this file is not itself a finding
        allowlisted.write_text(f"<?php\nuse AuthenticatableTrait, {trait};\n$aws = '{fake}';\n")
        report = self.tmp / "report"
        report.mkdir()
        docker_run(self.policy["tools"]["gitleaks"], ["dir", "/repo", "--config", "/cfg/.gitleaks.toml", "--no-banner", "--redact",
                                                      "--exit-code", "0", "--report-format", "json", "--report-path", "/out/r.json"],
                   mounts=[(self.tmp, "/repo", "ro"), (REPO_ROOT / ".gitleaks.toml", "/cfg/.gitleaks.toml", "ro"), (report, "/out", "rw")],
                   network="none")
        findings = json.loads((report / "r.json").read_text())
        self.assertEqual([f["RuleID"] for f in findings], ["aws-access-token"], "the planted key is found; the allowlisted trait name is not")
        self.assertNotIn(fake, json.dumps(findings), "the report is redacted")


if __name__ == "__main__":
    unittest.main()
