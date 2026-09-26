"""verify-artifact end to end: the full success path ends VERIFIED; every failure case
fails closed with its bounded code. Signatures are REAL cosign signatures made with an
ephemeral per-run key (the same verification path as production); these tests need
Docker and are reported as skipped -- never as passed -- where it is unavailable."""

from __future__ import annotations

import json
import shutil
import tempfile
import unittest
from pathlib import Path

from lycenza_release import evidence as ev
from lycenza_release.policy import load_policy
from lycenza_release.signing import EphemeralTestSigner
from lycenza_release.util import ReleaseError, write_json
from lycenza_release.verify import Context, verify
from lycenza_release.verify import main as verify_main
from support import NOW, Evidence, docker_available, make_archive


@unittest.skipUnless(docker_available(), "Docker is required for real cosign signatures (reported as SKIPPED, not passed)")
class VerifyArtifactTest(unittest.TestCase):
    signer: EphemeralTestSigner

    @classmethod
    def setUpClass(cls) -> None:
        cls.signer = EphemeralTestSigner(load_policy()["tools"]["cosign"]).__enter__()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.signer.__exit__(None, None, None)

    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def evidence(self, **kwargs) -> Evidence:
        return Evidence(self.root, self.signer, **kwargs)

    def run_verify(self, e: Evidence, *, digest: str | None = None, archive: Path | None = None, key: Path | None = None,
                   rescan: Path | None = None, now=NOW) -> tuple[str, dict[str, str]]:
        ctx = Context(image=e.image, digest=digest or e.digest, evidence=e.out, repo=e.repo, now=now,
                      archive=archive if archive is not None else e.archive, test_public_key=key if key is not None else e.public_key(),
                      rescan_report=rescan)
        verdict, checks = verify(ctx)
        return verdict, {c.name: c.code for c in checks if not c.ok}

    def assertFails(self, e: Evidence, code: str, **kwargs) -> None:
        verdict, failed = self.run_verify(e, **kwargs)
        self.assertEqual(verdict, ev.FAILED)
        self.assertIn(code, failed.values(), f"expected {code}, got {failed}")

    # --- success ---------------------------------------------------------------------

    def test_full_success_ends_verified(self) -> None:
        e = self.evidence()
        verdict, failed = self.run_verify(e)
        self.assertEqual((verdict, failed), (ev.VERIFIED, {}))

        record = self.root / "record.json"
        status = verify_main(["--image", "app", "--digest", e.digest, "--evidence", str(e.out), "--archive", str(e.archive),
                              "--repo", str(e.repo), "--test-public-key", str(e.public_key()), "--record", str(record),
                              "--record-state", "--now", "2026-09-26T10:00:00Z"])
        self.assertEqual(status, 0)
        self.assertEqual(json.loads((e.out / "state" / "app.json").read_text())["state"], "VERIFIED")
        self.assertEqual(json.loads(record.read_text())["signing"], "ephemeral-test (NON-PRODUCTION)")

    def test_the_gateway_image_success_path(self) -> None:
        verdict, failed = self.run_verify(self.evidence(image="ai-gateway"))
        self.assertEqual((verdict, failed), (ev.VERIFIED, {}))

    def test_rollback_reverification_of_retained_evidence(self) -> None:
        """Rollback redeploys a previously verified digest: its retained evidence re-verifies, days later."""
        e = self.evidence()
        later = NOW.replace(day=NOW.day + 3)
        self.assertEqual(self.run_verify(e, now=later)[0], ev.VERIFIED)
        clean = self.root / "rescan-clean.json"
        clean.write_text((e.base() / "grype.json").read_text())
        self.assertEqual(self.run_verify(e, now=later, rescan=clean)[0], ev.VERIFIED)
        # A Critical found since, in the old digest: operator/security judgement, never an automatic pass.
        critical = self.root / "rescan-critical.json"
        critical.write_text(Path(__file__).with_name("fixtures").joinpath("grype-findings.json").read_text().replace("__DIGEST__", e.digest))
        self.assertFails(e, "rescan_blocking_findings_require_judgement", now=later, rescan=critical)

    # --- production custody is not configured ------------------------------------------------

    def test_without_the_test_key_there_is_no_signing_identity(self) -> None:
        e = self.evidence()
        ctx = Context(image="app", digest=e.digest, evidence=e.out, repo=e.repo, now=NOW, archive=e.archive)
        verdict, checks = verify(ctx)
        self.assertEqual(verdict, ev.FAILED)
        self.assertIn("signing_identity_not_configured", [c.code for c in checks])

    # --- failure cases --------------------------------------------------------------------------

    def test_missing_evidence(self) -> None:
        for rel in ("images/app/sbom.spdx.json", "images/app/provenance.intoto.json", "tests/complete-regression.json", "bundle.json.sig"):
            with self.subTest(rel):
                e = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
                (e.out / rel).unlink()
                self.assertFails(e, "evidence_missing")

    def test_malformed_evidence(self) -> None:
        e = self.evidence()
        (e.base() / "grype.json").write_text("{not json")
        e.sign()
        verdict, failed = self.run_verify(e)
        self.assertEqual(verdict, ev.FAILED)
        self.assertTrue({"evidence_malformed", "provenance_byproduct_mismatch_grype_json"} & set(failed.values()), failed)

    def test_unsigned_or_wrongly_signed(self) -> None:
        e = self.evidence()
        (e.out / ev.SIGNATURE).write_text("MEUCIQDtampered")
        self.assertFails(e, "signature_invalid")

        e2 = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
        with EphemeralTestSigner(load_policy()["tools"]["cosign"]) as other:
            other.export_public_key(self.root / "other.pub")
        self.assertFails(e2, "signature_invalid", key=self.root / "other.pub")

    def test_evidence_modified_after_signing(self) -> None:
        e = self.evidence()
        write_json(e.base() / "image-scan.json", {"config_issues": [], "rootfs_issues": [], "rootfs_allowlisted": [], "x": 1})
        self.assertFails(e, "bundle_file_modified")
        e2 = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
        (e2.out / "extra.json").write_text("{}")
        self.assertFails(e2, "bundle_file_set_mismatch")

    def test_digest_mismatches(self) -> None:
        e = self.evidence()
        self.assertFails(e, "digest_mismatch", digest="sha256:" + "0" * 64)
        other = self.root / "other.oci.tar"
        make_archive(other, env=["PATH=/bin", "X=1"])
        self.assertFails(e, "archive_digest_mismatch", archive=other)
        arm = self.root / "arm.oci.tar"
        make_archive(arm, platform=("linux", "arm64"))
        self.assertFails(e, "archive_digest_mismatch", archive=arm)

    def test_stale_or_unpinned_scanner(self) -> None:
        e = self.evidence()
        e.set_grype("grype-clean.json", built="2026-09-24T06:00:00Z")
        e.refresh()
        self.assertFails(e, "scanner_db_stale")
        e2 = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
        report = json.loads((e2.base() / "grype.json").read_text())
        report["descriptor"]["version"] = "0.99.0"
        write_json(e2.base() / "grype.json", report)
        e2.refresh()
        self.assertFails(e2, "scanner_version_mismatch")

    def test_blocking_vulnerabilities(self) -> None:
        e = self.evidence()
        e.set_grype("grype-findings.json")
        e.refresh()
        self.assertFails(e, "vulnerability_policy_blocking_findings")

    def test_language_advisory_blocks(self) -> None:
        e = self.evidence(image="ai-gateway")
        shutil.copyfile(Path(__file__).with_name("fixtures") / "pip-audit-findings.json", e.out / "audits" / "pip-audit.json")
        e.sign()
        self.assertFails(e, "vulnerability_policy_blocking_findings")

    def test_expired_exception_file(self) -> None:
        e = self.evidence()
        verdict, failed = self.run_verify(e, now=NOW)
        self.assertEqual(verdict, ev.VERIFIED)
        import lycenza_release.exceptions as exc_module
        original = exc_module.EXCEPTIONS_FILE
        exc_module.EXCEPTIONS_FILE = Path(__file__).with_name("fixtures") / "exceptions" / "expired.json"
        try:
            self.assertFails(e, "exceptions_invalid")
        finally:
            exc_module.EXCEPTIONS_FILE = original

    def test_test_linkage(self) -> None:
        e = self.evidence()
        write_json(e.out / "tests" / "complete-regression.json", {"suite": "complete-regression", "commit": e.commit, "run_id": "999-1", "result": "passed"})
        e.sign()
        self.assertFails(e, "test_linkage_mismatch")
        e2 = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
        write_json(e2.out / "tests" / "complete-regression.json", {"suite": "complete-regression", "commit": e2.commit, "run_id": e2.run_id, "result": "failed"})
        e2.sign()
        self.assertFails(e2, "complete_regression_not_passed")

    def test_source_not_on_protected_main(self) -> None:
        self.assertFails(self.evidence(on_main=False), "source_not_on_protected_main")

    def test_secret_findings(self) -> None:
        e = self.evidence()
        write_json(e.base() / "image-scan.json", {"config_issues": [{"code": "secret_shaped_env", "where": "env:DB_PASSWORD"}], "rootfs_issues": []})
        e.sign()
        self.assertFails(e, "image_config_or_filesystem_findings")
        e2 = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
        write_json(e2.out / "source" / "gitleaks.json", [{"RuleID": "generic-api-key", "File": "x", "Secret": "REDACTED"}])
        e2.sign()
        self.assertFails(e2, "source_secret_scan_findings")

    def test_forbidden_files_and_deploy_gated_states(self) -> None:
        e = self.evidence()
        (e.out / "signing" / "cosign.key").write_text("x")
        self.assertFails(e, "evidence_contains_forbidden_file")
        with self.assertRaises(ReleaseError):
            ev.write_bundle(e.out, e.commit, e.run_id, ["app"])
        for state in ("PUBLISHED", "PROMOTED"):
            with self.assertRaises(ReleaseError):
                ev.write_state(e.out, "app", state, e.digest)
        e2 = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
        write_json(e2.out / "state" / "app.json", {"state": "PROMOTED"})
        self.assertFails(e2, "state_not_permitted")

    def test_provenance_and_sbom_mismatch(self) -> None:
        e = self.evidence()
        e.write_provenance(run_id="other-run")
        e.sign()
        self.assertFails(e, "provenance_run_mismatch")
        e2 = Evidence(Path(tempfile.mkdtemp(dir=self.root)), self.signer)
        sbom = json.loads((e2.base() / "sbom.spdx.json").read_text())
        sbom["packages"][0]["versionInfo"] = "sha256:" + "9" * 64
        write_json(e2.base() / "sbom.spdx.json", sbom)
        e2.refresh()
        self.assertFails(e2, "sbom_subject_mismatch")


if __name__ == "__main__":
    unittest.main()
