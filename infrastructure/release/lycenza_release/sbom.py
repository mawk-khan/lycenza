"""SPDX 2.3 JSON SBOM checks (ADR 0052 section 3.6).

The SBOM must describe THIS artifact: its root CONTAINER package carries the
image manifest digest; it must be Syft output of the pinned version and
cover the ecosystems the policy requires for the image.
"""

from __future__ import annotations

from collections import Counter
from typing import Any

from .util import parse_time


def summarize(document: dict[str, Any]) -> dict[str, Any]:
    purls: Counter[str] = Counter()
    for package in document.get("packages", []):
        for ref in package.get("externalRefs", []) or []:
            if ref.get("referenceType") == "purl":
                purls[str(ref.get("referenceLocator", "")).split("/")[0].removeprefix("pkg:")] += 1
    return {"packages": len(document.get("packages", [])), "purl_types": dict(sorted(purls.items()))}


def verify_sbom(document: Any, manifest_digest: str, required_purl_types: list[str], syft_version: str) -> list[str]:
    if not isinstance(document, dict):
        return ["sbom_malformed"]
    errors = []
    expected = {"spdxVersion": "SPDX-2.3", "dataLicense": "CC0-1.0", "SPDXID": "SPDXRef-DOCUMENT"}
    for key, value in expected.items():
        if document.get(key) != value:
            errors.append(f"sbom_{key.lower()}_invalid")
    if not str(document.get("documentNamespace", "")).startswith("https://"):
        errors.append("sbom_namespace_invalid")
    creation = document.get("creationInfo") or {}
    try:
        parse_time(str(creation.get("created", "")))
    except ValueError:
        errors.append("sbom_created_invalid")
    if f"Tool: syft-{syft_version}" not in (creation.get("creators") or []):
        errors.append("sbom_generator_unexpected")

    packages = document.get("packages")
    if not isinstance(packages, list) or not packages:
        return errors + ["sbom_empty"]

    described = {r.get("relatedSpdxElement") for r in document.get("relationships", []) or []
                 if r.get("spdxElementId") == "SPDXRef-DOCUMENT" and r.get("relationshipType") == "DESCRIBES"}
    roots = [p for p in packages if p.get("SPDXID") in described and p.get("primaryPackagePurpose") == "CONTAINER"]
    digest_hex = manifest_digest.removeprefix("sha256:")
    if len(roots) != 1 or roots[0].get("versionInfo") != manifest_digest or not any(
        c.get("algorithm") == "SHA256" and c.get("checksumValue") == digest_hex for c in roots[0].get("checksums", []) or []
    ):
        errors.append("sbom_subject_mismatch")

    present = summarize(document)["purl_types"]
    errors += [f"sbom_missing_{t}_packages" for t in required_purl_types if not present.get(t)]
    return errors
