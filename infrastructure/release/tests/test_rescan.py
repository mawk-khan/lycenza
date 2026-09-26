"""The scheduled SBOM re-scan against fixtures (offline: a fixture Grype report stands
in for the fresh scan; the evaluator, freshness rule and SBOM subject check are real)."""

from __future__ import annotations

import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

from lycenza_release.qualify import main
from lycenza_release.schema import validate
from lycenza_release.util import RELEASE_DIR
from support import FIXTURES, spdx

DIGEST = "sha256:" + "5e" * 32


class RescanTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.sbom = self.root / "sbom.spdx.json"
        self.sbom.write_text(json.dumps(spdx(DIGEST, ["deb", "composer"])))

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def rescan(self, report: str, *extra: str, built: str | None = None) -> tuple[int, str]:
        data = json.loads((FIXTURES / report).read_text().replace("__DIGEST__", DIGEST))
        if built:
            data["descriptor"]["db"]["status"]["built"] = built
        path = self.root / "report.json"
        path.write_text(json.dumps(data))
        out = io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(out):
            status = main(["rescan", "--sbom", f"app={self.sbom}", "--out", str(self.root / "out"), "--report", str(path), *extra])
        return status, out.getvalue()

    def test_a_clean_rescan_passes(self) -> None:
        status, output = self.rescan("grype-clean.json", "--expect", f"app={DIGEST}")
        self.assertEqual(status, 0, output)
        record = json.loads((self.root / "out" / "app.rescan.json").read_text())
        self.assertEqual((record["verdict"], record["digest"]), ("PASS", DIGEST))

    def test_new_blocking_findings_are_reported_not_acted_on(self) -> None:
        status, output = self.rescan("grype-findings.json")
        self.assertEqual(status, 1)
        self.assertIn("rescan app: FAIL", output)
        self.assertEqual(len(json.loads((self.root / "out" / "app.rescan.json").read_text())["blocking"]), 3)

    def test_a_stale_database_fails(self) -> None:
        status, output = self.rescan("grype-clean.json", built="2026-09-24T00:00:00Z")
        self.assertEqual(status, 1)
        self.assertIn("scanner_db_stale", output)

    def test_the_sbom_must_describe_the_retained_digest(self) -> None:
        status, output = self.rescan("grype-clean.json", "--expect", "app=sha256:" + "0" * 64)
        self.assertEqual(status, 1)
        self.assertIn("rescan_sbom_subject_mismatch", output)

    def test_retained_releases_file(self) -> None:
        schema = json.loads((RELEASE_DIR / "schema" / "retained-releases.schema.json").read_text())
        self.assertEqual(validate(json.loads((RELEASE_DIR / "retained-releases.json").read_text()), schema), [])
        good = {"schema_version": 1, "releases": [{"image": "app", "digest": DIGEST, "role": "current", "evidence_run_id": "123"}]}
        self.assertEqual(validate(good, schema), [])
        for bad in ({"image": "app", "digest": "latest", "role": "current", "evidence_run_id": "1"},
                    {"image": "app", "digest": DIGEST, "role": "promoted", "evidence_run_id": "1"},
                    {"image": "web", "digest": DIGEST, "role": "current", "evidence_run_id": "1"}):
            self.assertTrue(validate({"schema_version": 1, "releases": [bad]}, schema), bad)


if __name__ == "__main__":
    unittest.main()
