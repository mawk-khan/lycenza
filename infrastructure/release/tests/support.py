"""Test support: a throwaway git repository, a synthetic OCI archive and a complete,
signed evidence set built with the SAME generators qualify uses (build_statement,
write_bundle, EphemeralTestSigner). Nothing here touches a registry or a real key."""

from __future__ import annotations

import gzip
import hashlib
import io
import json
import os
import shutil
import subprocess
import tarfile
import tempfile
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from lycenza_release import evidence as ev
from lycenza_release.policy import load_policy
from lycenza_release.provenance import build_statement
from lycenza_release.util import REPO_ROOT, sha256_file, write_json

FIXTURES = Path(__file__).resolve().parent / "fixtures"
NOW = datetime(2026, 9, 26, 10, 0, 0, tzinfo=UTC)
TODAY = NOW.date()


def docker_available() -> bool:
    if shutil.which("docker") is None:
        return False
    return subprocess.run(["docker", "info"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False).returncode == 0


def fixture(name: str) -> Any:
    return json.loads((FIXTURES / name).read_text())


def git(repo: Path, *args: str) -> str:
    env = dict(os.environ, GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@example.invalid", GIT_COMMITTER_NAME="t",
               GIT_COMMITTER_EMAIL="t@example.invalid", GIT_CONFIG_NOSYSTEM="1", HOME=str(repo))
    return subprocess.run(["git", "-C", str(repo), *args], env=env, check=True, capture_output=True, text=True).stdout.strip()


def make_repo(root: Path, on_main: bool = True) -> tuple[Path, str]:
    """A repository whose HEAD holds the real production Dockerfiles, runtime security contract and minimal locks."""
    repo = root / "repo"
    for rel in ("infrastructure/docker/production/app.Dockerfile", "infrastructure/docker/production/ai.Dockerfile",
                "infrastructure/release/runtime-security.json", "infrastructure/release/schema/runtime-security.schema.json"):
        (repo / rel).parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(REPO_ROOT / rel, repo / rel)
    (repo / "apps/platform").mkdir(parents=True)
    (repo / "apps/platform/composer.lock").write_text(json.dumps({"packages": [{"name": "example/package", "version": "v1.2.3"}]}))
    (repo / "apps/platform/package-lock.json").write_text(json.dumps({"packages": {"": {}, "node_modules/example-lib": {"version": "3.0.0"}}}))
    git(repo.parent, "init", "-q", "-b", "main", str(repo))
    git(repo, "add", "-A")
    git(repo, "commit", "-qm", "fixture")
    commit = git(repo, "rev-parse", "HEAD")
    if on_main:
        git(repo, "update-ref", "refs/remotes/origin/main", commit)
    else:
        git(repo, "commit", "-q", "--allow-empty", "-m", "main moved on")
        git(repo, "update-ref", "refs/remotes/origin/main", "HEAD")
        git(repo, "checkout", "-q", "-b", "unmerged", commit)
        git(repo, "commit", "-q", "--allow-empty", "-m", "not on main")
        commit = git(repo, "rev-parse", "HEAD")
    return repo, commit


def _blob(layout: Path, data: bytes) -> dict[str, Any]:
    digest = hashlib.sha256(data).hexdigest()
    (layout / "blobs" / "sha256").mkdir(parents=True, exist_ok=True)
    (layout / "blobs" / "sha256" / digest).write_bytes(data)
    return {"digest": f"sha256:{digest}", "size": len(data)}


def make_archive(path: Path, *, env: list[str] | None = None, labels: dict[str, str] | None = None,
                 history: list[str] | None = None, platform: tuple[str, str] = ("linux", "amd64")) -> tuple[str, str]:
    """A minimal single-platform OCI image archive; returns (manifest digest, config digest)."""
    with tempfile.TemporaryDirectory() as tmp:
        layout = Path(tmp)
        layer_tar = io.BytesIO()
        with tarfile.open(fileobj=layer_tar, mode="w") as tar:
            info = tarfile.TarInfo("srv/hello.txt")
            data = b"hello\n"
            info.size = len(data)
            tar.addfile(info, io.BytesIO(data))
        layer = _blob(layout, gzip.compress(layer_tar.getvalue(), mtime=0))
        config = {
            "architecture": platform[1], "os": platform[0],
            "config": {"Env": env or ["PATH=/usr/bin"], "Labels": labels or {}, "Cmd": ["web"]},
            "rootfs": {"type": "layers", "diff_ids": ["sha256:" + hashlib.sha256(layer_tar.getvalue()).hexdigest()]},
            "history": [{"created_by": h} for h in (history or ["COPY hello.txt /srv/ # buildkit"])],
        }
        config_blob = _blob(layout, json.dumps(config).encode())
        manifest = {"schemaVersion": 2, "mediaType": "application/vnd.oci.image.manifest.v1+json",
                    "config": {"mediaType": "application/vnd.oci.image.config.v1+json", **config_blob},
                    "layers": [{"mediaType": "application/vnd.oci.image.layer.v1.tar+gzip", **layer}]}
        manifest_blob = _blob(layout, json.dumps(manifest).encode())
        (layout / "oci-layout").write_text('{"imageLayoutVersion":"1.0.0"}')
        (layout / "index.json").write_text(json.dumps({"schemaVersion": 2, "manifests": [
            {"mediaType": "application/vnd.oci.image.manifest.v1+json", **manifest_blob,
             "platform": {"architecture": platform[1], "os": platform[0]}}]}))
        with tarfile.open(path, "w") as tar:
            for item in sorted(layout.rglob("*")):
                tar.add(item, arcname=item.relative_to(layout).as_posix(), recursive=False)
        return manifest_blob["digest"], config_blob["digest"]


def spdx(digest: str, purl_types: list[str]) -> dict[str, Any]:
    packages = [{
        "name": "image", "SPDXID": "SPDXRef-DocumentRoot-Image-image", "versionInfo": digest,
        "checksums": [{"algorithm": "SHA256", "checksumValue": digest[7:]}], "primaryPackagePurpose": "CONTAINER",
        "downloadLocation": "NOASSERTION",
    }]
    for index, kind in enumerate(purl_types):
        packages.append({"name": f"pkg{index}", "SPDXID": f"SPDXRef-Package-{index}", "versionInfo": "1.0",
                         "downloadLocation": "NOASSERTION",
                         "externalRefs": [{"referenceCategory": "PACKAGE-MANAGER", "referenceType": "purl",
                                           "referenceLocator": f"pkg:{kind}/pkg{index}@1.0"}]})
    return {
        "spdxVersion": "SPDX-2.3", "dataLicense": "CC0-1.0", "SPDXID": "SPDXRef-DOCUMENT", "name": "image",
        "documentNamespace": "https://anchore.com/syft/image/test",
        "creationInfo": {"created": "2026-09-26T09:00:00Z", "creators": ["Organization: Anchore, Inc", "Tool: syft-1.33.0"]},
        "packages": packages,
        "relationships": [{"spdxElementId": "SPDXRef-DOCUMENT", "relatedSpdxElement": "SPDXRef-DocumentRoot-Image-image", "relationshipType": "DESCRIBES"}],
    }


class Evidence:
    """A complete evidence directory for one image, plus helpers to mutate and re-sign it."""

    def __init__(self, root: Path, signer: Any, *, image: str = "app", on_main: bool = True) -> None:
        self.policy = load_policy()
        self.root, self.signer, self.image = root, signer, image
        self.repo, self.commit = make_repo(root, on_main=on_main)
        self.out = root / "evidence"
        self.archive = root / f"{image}.oci.tar"
        self.digest, self.config_digest = make_archive(self.archive)
        self.run_id = "1234-1"
        self._write_all()

    def base(self) -> Path:
        return self.out / "images" / self.image

    def _write_all(self) -> None:
        out, base, image = self.out, self.base(), self.policy["images"][self.image]
        write_json(out / "run.json", {"commit": self.commit, "run_id": self.run_id, "builder_id": "local-operator:test"})
        write_json(out / "tests" / "complete-regression.json", {"suite": "complete-regression", "commit": self.commit,
                                                                 "run_id": self.run_id, "result": "passed"})
        write_json(out / "source" / "gitleaks.json", [])
        shutil.copyfile(FIXTURES / "composer-audit-clean.json", self._mk(out / "audits" / "composer-audit.json"))
        shutil.copyfile(FIXTURES / "npm-audit-clean.json", out / "audits" / "npm-audit.json")
        shutil.copyfile(FIXTURES / "pip-audit-clean.json", out / "audits" / "pip-audit.json")
        write_json(base / "build.json", {"name": image["name"], "manifest_digest": self.digest, "config_digest": self.config_digest,
                                         "commit": self.commit, "run_id": self.run_id, "builder_image": self.policy["tools"]["buildkit"],
                                         "dockerfile": image["dockerfile"], "started": "2026-09-26T08:50:00Z", "finished": "2026-09-26T08:55:00Z"})
        write_json(base / "sbom.spdx.json", spdx(self.digest, image["sbom_required_purl_types"]))
        self.set_grype("grype-clean.json")
        write_json(base / "gitleaks.json", [])
        write_json(base / "image-scan.json", {"config_issues": [], "rootfs_issues": [], "rootfs_allowlisted": []})
        self.write_verify_images()
        self.write_provenance()
        self.sign()

    @staticmethod
    def _mk(path: Path) -> Path:
        path.parent.mkdir(parents=True, exist_ok=True)
        return path

    def set_grype(self, name: str, *, built: str | None = None, timestamp: str | None = None) -> None:
        report = json.loads((FIXTURES / name).read_text().replace("__DIGEST__", self.digest))
        if built:
            report["descriptor"]["db"]["status"]["built"] = built
        if timestamp:
            report["descriptor"]["timestamp"] = timestamp
        write_json(self.base() / "grype.json", report)
        write_json(self.base() / "scanner.json", {"image": self.policy["tools"]["grype"], "version": "0.100.0",
                                                  "db_built": report["descriptor"]["db"]["status"]["built"]})

    def write_verify_images(self, *, exit_code: int = 0, config_digest: str | None = None, omit: str | None = None) -> None:
        """The qualify verify-images record: every runtime-security check the contract requires, passed for this artifact."""
        contract = json.loads((REPO_ROOT / "infrastructure/release/runtime-security.json").read_text())
        checks = [f"PASS  {name}" for name in contract["images"][self.image]["required_verification_checks"] if name != omit]
        write_json(self.out / "verify-images.json", {"exit_code": exit_code, "images": {self.image: config_digest or self.config_digest},
                                                     "checks": [*checks, "verify-images: all checks passed"]})

    def write_provenance(self, **overrides: Any) -> None:
        base = self.base()
        dockerfile = git(self.repo, "show", f"{self.commit}:{self.policy['images'][self.image]['dockerfile']}") + "\n"
        args = dict(policy=self.policy, image_key=self.image, manifest_digest=self.digest, commit=self.commit,
                    dockerfile_text=dockerfile, builder_id="local-operator:test", run_id=self.run_id,
                    started="2026-09-26T08:50:00Z", finished="2026-09-26T08:55:00Z",
                    byproducts=[{"name": "sbom.spdx.json", "digest": {"sha256": sha256_file(base / "sbom.spdx.json")}},
                                {"name": "grype.json", "digest": {"sha256": sha256_file(base / "grype.json")}}],
                    test_run={"test_run_id": self.run_id, "suite": "complete-regression", "result": "passed"})
        args.update(overrides)
        write_json(base / "provenance.intoto.json", build_statement(**args))

    def sign(self) -> None:
        for stale in (self.out / ev.BUNDLE, self.out / ev.SIGNATURE):
            stale.unlink(missing_ok=True)
        write_json(self.out / "signing.json", self.signer.export_public_key(self.out / "signing" / "ephemeral-test.pub"))
        ev.write_bundle(self.out, self.commit, self.run_id, [self.image])
        self.signer.sign(self.out / ev.BUNDLE, self.out / ev.SIGNATURE)

    def refresh(self) -> None:
        """After mutating a byproduct: regenerate provenance and re-sign, so only the intended defect remains."""
        self.write_provenance()
        self.sign()

    def public_key(self) -> Path:
        return self.out / "signing" / "ephemeral-test.pub"
