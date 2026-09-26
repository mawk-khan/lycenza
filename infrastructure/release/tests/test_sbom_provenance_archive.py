"""SBOM checks, provenance generation/verification and the OCI archive reader."""

from __future__ import annotations

import copy
import json
import re
import tarfile
import tempfile
import unittest
from pathlib import Path

from lycenza_release.oci import read_archive
from lycenza_release.policy import load_policy
from lycenza_release.provenance import base_images, build_statement, verify_statement
from lycenza_release.sbom import verify_sbom
from lycenza_release.util import REPO_ROOT, ReleaseError
from support import make_archive, spdx

DIGEST = "sha256:" + "ab" * 32
COMMIT = "c" * 40


class SbomTest(unittest.TestCase):
    def test_a_valid_sbom_for_the_digest(self) -> None:
        self.assertEqual(verify_sbom(spdx(DIGEST, ["deb", "composer"]), DIGEST, ["deb", "composer"], "1.33.0"), [])

    def test_failures(self) -> None:
        good = spdx(DIGEST, ["deb", "composer"])
        cases = {
            "sbom_subject_mismatch": (good, "sha256:" + "cd" * 32, ["deb"]),
            "sbom_missing_composer_packages": (spdx(DIGEST, ["deb"]), DIGEST, ["deb", "composer"]),
            "sbom_spdxversion_invalid": ({**good, "spdxVersion": "SPDX-2.2"}, DIGEST, ["deb"]),
            "sbom_generator_unexpected": ({**good, "creationInfo": {"created": "2026-09-26T00:00:00Z", "creators": ["Tool: syft-0.1.0"]}}, DIGEST, ["deb"]),
            "sbom_empty": ({**good, "packages": []}, DIGEST, ["deb"]),
            "sbom_malformed": ([], DIGEST, ["deb"]),
        }
        for code, (document, digest, required) in cases.items():
            with self.subTest(code):
                self.assertIn(code, verify_sbom(document, digest, required, "1.33.0"))


class ProvenanceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.policy = load_policy()
        self.dockerfile = (REPO_ROOT / "infrastructure/docker/production/app.Dockerfile").read_text()
        self.byproducts = {"sbom.spdx.json": "1" * 64, "grype.json": "2" * 64}
        self.statement = build_statement(
            policy=self.policy, image_key="app", manifest_digest=DIGEST, commit=COMMIT, dockerfile_text=self.dockerfile,
            builder_id="local-operator:test", run_id="9-1", started="2026-09-26T00:00:00Z", finished="2026-09-26T00:05:00Z",
            byproducts=[{"name": k, "digest": {"sha256": v}} for k, v in self.byproducts.items()],
            test_run={"test_run_id": "9-1", "suite": "complete-regression", "result": "passed"})

    def verify(self, statement: dict, **overrides) -> list[str]:
        args = dict(policy=self.policy, image_key="app", manifest_digest=DIGEST, commit=COMMIT, run_id="9-1",
                    byproduct_digests=self.byproducts, dockerfile_text=self.dockerfile)
        args.update(overrides)
        return verify_statement(statement, **args)

    def test_the_statement_shape(self) -> None:
        self.assertEqual(self.statement["_type"], "https://in-toto.io/Statement/v1")
        self.assertEqual(self.statement["predicateType"], "https://slsa.dev/provenance/v1")
        self.assertEqual(self.statement["subject"], [{"name": "lycenza-app", "digest": {"sha256": "ab" * 32}}])
        names = [d.get("name") for d in self.statement["predicate"]["buildDefinition"]["resolvedDependencies"][1:]]
        self.assertEqual(names, ["PHP_IMAGE", "NODE_IMAGE", "COMPOSER_IMAGE"])
        self.assertEqual(self.verify(self.statement), [])

    def test_every_base_image_is_digest_pinned(self) -> None:
        for name in ("app", "ai"):
            text = (REPO_ROOT / f"infrastructure/docker/production/{name}.Dockerfile").read_text()
            self.assertEqual(len(base_images(text)), text.count("_IMAGE=") , f"{name}.Dockerfile has an unpinned base")

    def test_failures(self) -> None:
        mutations = {
            "provenance_subject_mismatch": lambda s: s["subject"][0]["digest"].update(sha256="ef" * 32),
            "provenance_source_mismatch": lambda s: s["predicate"]["buildDefinition"]["externalParameters"]["source"].update(ref="refs/heads/feature"),
            "provenance_run_mismatch": lambda s: s["predicate"]["runDetails"]["metadata"].update(invocationId="other"),
            "provenance_byproduct_mismatch_sbom_spdx_json": lambda s: s["predicate"]["runDetails"]["byproducts"][0]["digest"].update(sha256="3" * 64),
            "provenance_claims_a_slsa_level": lambda s: s["predicate"].update(slsaLevel=3),
            "provenance_contains_secret": lambda s: s["predicate"]["runDetails"]["builder"].update(id="dev-local-only-token"),
            "provenance_type_invalid": lambda s: s.update(predicateType="https://slsa.dev/provenance/v0.2"),
        }
        for code, mutate in mutations.items():
            with self.subTest(code):
                statement = copy.deepcopy(self.statement)
                mutate(statement)
                self.assertIn(code, self.verify(statement))
        pinned = re.search(r"@sha256:([0-9a-f]{64})", self.dockerfile).group(1)
        tampered = self.dockerfile.replace(pinned, ("0" if pinned[0] != "0" else "1") + pinned[1:], 1)
        self.assertNotEqual(tampered, self.dockerfile)
        self.assertIn("provenance_base_images_mismatch", self.verify(self.statement, dockerfile_text=tampered))
        self.assertIn("provenance_source_mismatch", self.verify(self.statement, commit="d" * 40))
        self.assertEqual(self.verify("not a statement"), ["provenance_malformed"])


class ArchiveTest(unittest.TestCase):
    def test_read_and_tamper(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "image.oci.tar"
            digest, config_digest = make_archive(path)
            image = read_archive(path)
            self.assertEqual((image.manifest_digest, image.config_digest, image.platform), (digest, config_digest, "linux/amd64"))

            tampered = Path(tmp) / "tampered.oci.tar"
            with tarfile.open(path) as src, tarfile.open(tampered, "w") as dst:
                for member in src.getmembers():
                    data = src.extractfile(member).read() if member.isfile() else None
                    if member.name == f"blobs/sha256/{config_digest[7:]}":
                        data = json.dumps({"os": "linux", "architecture": "amd64", "config": {"Env": ["X=1"]}}).encode()
                        member.size = len(data)
                    dst.addfile(member, __import__("io").BytesIO(data) if data is not None else None)
            with self.assertRaises(ReleaseError) as caught:
                read_archive(tampered)
            self.assertEqual(caught.exception.code, "archive_tampered")

            with self.assertRaises(ReleaseError) as caught:
                read_archive(Path(tmp) / "missing.tar")
            self.assertEqual(caught.exception.code, "archive_missing")


if __name__ == "__main__":
    unittest.main()
