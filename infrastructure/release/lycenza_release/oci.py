"""Read a single-platform OCI image archive (ADR 0052 section 3.1).

The artifact identity is the image MANIFEST digest. Every blob the manifest
references is re-hashed against its content address, so a tampered archive
cannot keep its digest.
"""

from __future__ import annotations

import hashlib
import json
import tarfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .util import DIGEST, ReleaseError

MANIFEST_TYPE = "application/vnd.oci.image.manifest.v1+json"
INDEX_TYPE = "application/vnd.oci.image.index.v1+json"


@dataclass(frozen=True)
class OciImage:
    manifest_digest: str
    config_digest: str
    manifest: dict[str, Any]
    config: dict[str, Any]
    platform: str


def _blob(archive: tarfile.TarFile, digest: str) -> bytes:
    if not DIGEST.match(digest):
        raise ReleaseError("archive_malformed", "digest")
    try:
        member = archive.extractfile(f"blobs/sha256/{digest[7:]}")
    except KeyError as exc:
        raise ReleaseError("archive_malformed", "missing blob") from exc
    if member is None:
        raise ReleaseError("archive_malformed", "blob is not a file")
    hasher, chunks = hashlib.sha256(), []
    for chunk in iter(lambda: member.read(1 << 20), b""):
        hasher.update(chunk)
        chunks.append(chunk)
    if "sha256:" + hasher.hexdigest() != digest:
        raise ReleaseError("archive_tampered", "blob content does not match its digest")
    return b"".join(chunks)


def read_archive(path: Path, verify_layers: bool = True) -> OciImage:
    try:
        archive = tarfile.open(path, "r:")
    except (OSError, tarfile.TarError) as exc:
        raise ReleaseError("archive_missing", path.name) from exc
    with archive:
        try:
            layout = json.loads(archive.extractfile("oci-layout").read())  # type: ignore[union-attr]
            index = json.loads(archive.extractfile("index.json").read())  # type: ignore[union-attr]
        except (KeyError, AttributeError, json.JSONDecodeError) as exc:
            raise ReleaseError("archive_malformed", "not an OCI image layout") from exc
        if layout.get("imageLayoutVersion") != "1.0.0":
            raise ReleaseError("archive_malformed", "layout version")
        manifests = index.get("manifests") if isinstance(index, dict) else None
        if not isinstance(manifests, list) or len(manifests) != 1 or manifests[0].get("mediaType") != MANIFEST_TYPE:
            raise ReleaseError("archive_malformed", "expected exactly one single-platform image manifest")
        descriptor = manifests[0]
        manifest = json.loads(_blob(archive, descriptor["digest"]))
        config = json.loads(_blob(archive, manifest["config"]["digest"]))
        if verify_layers:
            for layer in manifest.get("layers", []):
                _blob(archive, layer["digest"])
        platform = f"{config.get('os')}/{config.get('architecture')}"
        return OciImage(descriptor["digest"], manifest["config"]["digest"], manifest, config, platform)
