"""Canary PROOF against real tools (Docker required; reported as skipped, never as
passed, without it): a real image built with planted secrets is caught by the
history/env/label scan, and the pinned gitleaks with the repository's exact-match
allowlist still finds a real-looking secret planted in an allowlisted file, and its
history-only entries (E03.1) exempt one commit, never a value or a file."""

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

    def test_history_only_entries_exempt_one_commit_never_a_value_or_a_file(self) -> None:
        """E03.1 (ADR 0072 section 10): the 2c92af358 entries are scoped to that commit.
        The same fixture value committed again, and a new value in the same file, are
        both still findings in a full-history (git mode) scan."""
        repo = self.tmp / "history"
        target = repo / "apps/platform/tests/Unit/Domains/DnsVerificationTest.php"
        target.parent.mkdir(parents=True)
        git = ["git", "-C", str(repo), "-c", "user.name=canary", "-c", "user.email=canary@invalid"]
        subprocess.run([*git, "init", "-q"], check=True)
        historical = "k7Qm0bY4n2XcV9sLp1aZr8tWd3eF" + "h6gJu5iOy0PqRsT"  # assembled so this file is not itself a finding
        fresh = "".join(secrets.choice("abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789") for _ in range(43))
        commits = []
        for value in (historical, fresh):
            target.write_text(f"<?php\nclass DnsVerificationTest\n{{\n    private const TOKEN = '{value}';\n}}\n")
            subprocess.run([*git, "add", "-A"], check=True)
            subprocess.run([*git, "commit", "-q", "-m", "canary"], check=True)
            commits.append(subprocess.run([*git, "rev-parse", "HEAD"], capture_output=True, text=True, check=True).stdout.strip())
        report = self.tmp / "report"
        report.mkdir()
        docker_run(self.policy["tools"]["gitleaks"], ["git", "/repo", "--config", "/cfg/.gitleaks.toml", "--no-banner", "--redact",
                                                      "--exit-code", "0", "--report-format", "json", "--report-path", "/out/r.json"],
                   mounts=[(repo, "/repo", "ro"), (REPO_ROOT / ".gitleaks.toml", "/cfg/.gitleaks.toml", "ro"), (report, "/out", "rw")],
                   env={"GIT_CONFIG_COUNT": "1", "GIT_CONFIG_KEY_0": "safe.directory", "GIT_CONFIG_VALUE_0": "*"}, network="none")
        findings = json.loads((report / "r.json").read_text())
        self.assertEqual(sorted(commits), sorted(f["Commit"] for f in findings), "both commits are findings")
        self.assertEqual({"generic-api-key"}, {f["RuleID"] for f in findings})


if __name__ == "__main__":
    unittest.main()
