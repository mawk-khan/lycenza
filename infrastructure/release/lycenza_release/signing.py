"""Signing custody abstraction (ADR 0052 section 3.11).

Production custody is selected at deployment and named in the policy:
- `external-key`: a key held outside the repository (a cosign-supported KMS
  URI or an offline key's public half) -- no KMS SDK is added here, cosign
  resolves the reference itself;
- `keyless`: a Sigstore certificate identity + OIDC issuer.
Neither is configured: NO REAL SIGNING IDENTITY/KEY IS CONFIGURED.

For repository qualification an EPHEMERAL, per-run key pair is generated in
a private temporary directory, used once and destroyed; its public half is
kept with the evidence and every record says NON-PRODUCTION. Verification
goes through the same `Verifier.verify()` path production would use.
"""

from __future__ import annotations

import os
import secrets
import shutil
import stat
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .tools import docker_run
from .util import ReleaseError, sha256_file

NON_PRODUCTION = "NON-PRODUCTION: ephemeral per-run test key; private key destroyed after signing; never valid for promotion"


@dataclass(frozen=True)
class Verifier:
    kind: str
    cosign_image: str
    key_ref: str = ""
    identity: str = ""
    issuer: str = ""
    non_production: bool = False

    def command(self, blob: str, signature: str, key_mount: str = "") -> list[str]:
        if self.kind == "public-key":
            # 0O.6A signs with transparency-log upload disabled (no external
            # service); the chosen production custody decides tlog use.
            return ["verify-blob", "--key", key_mount or self.key_ref, "--signature", signature, "--insecure-ignore-tlog=true", blob]
        if self.kind == "keyless":
            return ["verify-blob", "--bundle", signature, "--certificate-identity", self.identity,
                    "--certificate-oidc-issuer", self.issuer, blob]
        raise ReleaseError("signing_identity_not_configured")

    def verify(self, blob: Path, signature: Path) -> bool:
        if self.kind == "unconfigured":
            raise ReleaseError("signing_identity_not_configured")
        mounts = [(blob.parent.resolve(), "/evidence", "ro")]
        key_mount = ""
        if self.kind == "public-key" and Path(self.key_ref).is_file():
            mounts.append((Path(self.key_ref).resolve().parent, "/key", "ro"))
            key_mount = f"/key/{Path(self.key_ref).name}"
        if signature.parent.resolve() != blob.parent.resolve():
            raise ReleaseError("signature_location_invalid")
        result = docker_run(self.cosign_image, self.command(f"/evidence/{blob.name}", f"/evidence/{signature.name}", key_mount),
                            mounts=mounts, network="none" if self.kind == "public-key" else None, check=False,
                            code="signature_tool_failed")
        return result.returncode == 0


def verifier_for(policy: dict[str, Any], test_public_key: Path | None = None) -> Verifier:
    cosign = policy["tools"]["cosign"]
    signing = policy["signing"]
    if test_public_key is not None:
        return Verifier("public-key", cosign, key_ref=str(test_public_key), non_production=True)
    if signing["custody"] == "external-key":
        return Verifier("public-key", cosign, key_ref=signing["public_key_ref"])
    if signing["custody"] == "keyless":
        return Verifier("keyless", cosign, identity=signing["certificate_identity"], issuer=signing["certificate_oidc_issuer"])
    return Verifier("unconfigured", cosign)


class EphemeralTestSigner:
    """A per-run cosign key pair in a 0700 temp dir; the private key never leaves it and is deleted on exit."""

    def __init__(self, cosign_image: str) -> None:
        self.cosign_image = cosign_image
        self._dir: Path | None = None
        self._password = ""

    def __enter__(self) -> EphemeralTestSigner:
        self._dir = Path(tempfile.mkdtemp(prefix="lycenza-ephemeral-signing-"))
        os.chmod(self._dir, stat.S_IRWXU)
        self._password = secrets.token_urlsafe(32)
        docker_run(self.cosign_image, ["generate-key-pair"], mounts=[(self._dir, "/keys", "rw")], workdir="/keys",
                   env={"COSIGN_PASSWORD": self._password}, network="none", code="signing_key_generation_failed")
        if not (self._dir / "cosign.key").is_file() or not (self._dir / "cosign.pub").is_file():
            raise ReleaseError("signing_key_generation_failed")
        return self

    def sign(self, blob: Path, signature: Path) -> None:
        assert self._dir is not None
        if signature.parent.resolve() != blob.parent.resolve():
            raise ReleaseError("signature_location_invalid")
        docker_run(self.cosign_image, ["sign-blob", "--key", "/keys/cosign.key", "--tlog-upload=false", "--yes",
                                       "--output-signature", f"/evidence/{signature.name}", f"/evidence/{blob.name}"],
                   mounts=[(self._dir, "/keys", "ro"), (blob.parent.resolve(), "/evidence", "rw")],
                   env={"COSIGN_PASSWORD": self._password}, network="none", code="signing_failed")

    def export_public_key(self, destination: Path) -> dict[str, str]:
        assert self._dir is not None
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(self._dir / "cosign.pub", destination)
        return {"custody": "ephemeral-test", "non_production": "true", "statement": NON_PRODUCTION,
                "public_key": destination.name, "public_key_sha256": sha256_file(destination)}

    def __exit__(self, *exc: object) -> None:
        self._password = ""
        if self._dir is not None:
            shutil.rmtree(self._dir, ignore_errors=True)
            self._dir = None
