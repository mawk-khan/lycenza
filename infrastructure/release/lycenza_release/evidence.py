"""Release evidence layout, the signed bundle manifest and artifact states.

    <evidence>/
      run.json                              commit, run id, builder id, lineage
      tests/complete-regression.json        the same-run complete regression record
      source/gitleaks.json                  source secret scan (redacted report)
      audits/{composer,npm,pip}-audit.json  unmodified language audit reports
      images/<key>/build.json               digest, config digest, labels, timings
      images/<key>/sbom.spdx.json           Syft SPDX 2.3 SBOM of the final image
      images/<key>/grype.json               unmodified Grype report
      images/<key>/scanner.json             scanner + database version/age
      images/<key>/gitleaks.json            image filesystem secret scan (redacted)
      images/<key>/image-scan.json          config/history/env/label + rootfs canary scan
      images/<key>/provenance.intoto.json   in-toto Statement v1 / SLSA Provenance v1
      bundle.json, bundle.json.sig          the signed manifest of everything above
      signing.json, signing/ephemeral-test.pub
      state/<key>.json                      BUILT | VERIFIED | FAIL (verify-artifact)

Never in evidence: private keys, `.env`, databases, environment dumps,
credential-bearing logs, the OCI archives themselves.
"""

from __future__ import annotations

from pathlib import Path
from typing import Any

from .util import ReleaseError, iso, load_json, sha256_file, utc_now, write_json

BUNDLE = "bundle.json"
SIGNATURE = "bundle.json.sig"
UNSIGNED = {BUNDLE, SIGNATURE}
UNSIGNED_DIRS = ("state/",)

# States (ADR 0052 section 3.14): the repository reaches at most VERIFIED.
BUILT, VERIFIED, FAILED = "BUILT", "VERIFIED", "FAIL"
DEPLOY_GATED = ("PUBLISHED", "PROMOTED")

REQUIRED_COMMON = ("run.json", "tests/complete-regression.json", "source/gitleaks.json",
                   "audits/composer-audit.json", "audits/npm-audit.json", "audits/pip-audit.json",
                   "signing.json")
REQUIRED_PER_IMAGE = ("build.json", "sbom.spdx.json", "grype.json", "scanner.json", "gitleaks.json",
                      "image-scan.json", "provenance.intoto.json")

FORBIDDEN_NAMES = (".env", "cosign.key", "id_rsa", "id_ed25519")
FORBIDDEN_SUFFIXES = (".key", ".pem", ".sqlite", ".sql", ".dump", ".oci.tar", ".tar")


def evidence_files(root: Path) -> list[str]:
    files = []
    for path in sorted(root.rglob("*")):
        if path.is_file():
            rel = path.relative_to(root).as_posix()
            if rel not in UNSIGNED and not rel.startswith(UNSIGNED_DIRS):
                files.append(rel)
    return files


def forbidden_files(root: Path) -> list[str]:
    bad = []
    for path in root.rglob("*"):
        name = path.name
        if path.is_symlink() or name in FORBIDDEN_NAMES or name.startswith(".env") or (
            name.endswith(FORBIDDEN_SUFFIXES) and not name.endswith(".pub")
        ):
            bad.append(path.relative_to(root).as_posix())
    return sorted(bad)


def image_summary(root: Path, key: str) -> dict[str, str]:
    base = root / "images" / key
    build = load_json(base / "build.json")
    return {
        "name": build["name"],
        "digest": build["manifest_digest"],
        "sbom_sha256": sha256_file(base / "sbom.spdx.json"),
        "provenance_sha256": sha256_file(base / "provenance.intoto.json"),
        "vulnerability_report_sha256": sha256_file(base / "grype.json"),
    }


def write_bundle(root: Path, commit: str, run_id: str, images: list[str]) -> dict[str, Any]:
    bad = forbidden_files(root)
    if bad:
        raise ReleaseError("evidence_contains_forbidden_file", ", ".join(bad[:3]))
    bundle = {
        "schema_version": 1,
        "commit": commit,
        "run_id": run_id,
        "images": {key: image_summary(root, key) for key in images},
        "files": {rel: sha256_file(root / rel) for rel in evidence_files(root)},
    }
    write_json(root / BUNDLE, bundle)
    return bundle


def write_state(root: Path, key: str, state: str, digest: str, detail: dict[str, Any] | None = None) -> None:
    if state in DEPLOY_GATED:
        raise ReleaseError("state_not_permitted", state)
    write_json(root / "state" / f"{key}.json", {"image": key, "digest": digest, "state": state,
                                                "recorded_at": iso(utc_now()), **(detail or {})})
