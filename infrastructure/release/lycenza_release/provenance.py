"""in-toto Statement v1 with a SLSA Provenance v1 predicate (ADR 0052 section 3.10).

The SLSA *structure* is used without claiming a SLSA level. The statement
records only trusted, bounded values -- no environment dump, no secret or
secret reference, no user data.
"""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

from .imagescan import CANARIES, SHAPES
from .util import COMMIT, DIGEST, ReleaseError

STATEMENT_TYPE = "https://in-toto.io/Statement/v1"
PREDICATE_TYPE = "https://slsa.dev/provenance/v1"

_BASE_ARG = re.compile(r"^ARG ([A-Z_]+_IMAGE)=([a-z0-9][a-z0-9./_-]*):([A-Za-z0-9][A-Za-z0-9._-]*)@(sha256:[0-9a-f]{64})\s*$", re.M)


_SOURCE_URL = re.compile(r"\bLYCENZA_([A-Z0-9]+)_URL=(https://\S+?)\s*\\?$", re.M)


def base_images(dockerfile_text: str) -> list[dict[str, Any]]:
    """The digest-pinned base-image ARGs of a production Dockerfile, followed by
    any verified upstream source archives it builds from (Phase 0O.6D:
    `LYCENZA_<NAME>_URL` + `LYCENZA_<NAME>_SHA256` declarations)."""
    images = [
        {"name": arg, "uri": f"pkg:docker/{repo}@{tag}", "digest": {"sha256": digest[7:]}}
        for arg, repo, tag, digest in _BASE_ARG.findall(dockerfile_text)
    ]
    sources = []
    for name, url in _SOURCE_URL.findall(dockerfile_text):
        sha = re.search(rf"\bLYCENZA_{name}_SHA256=([0-9a-f]{{64}})\b", dockerfile_text)
        if sha is None:
            raise ReleaseError("provenance_input_invalid", f"{name} source has no pinned sha256")
        sources.append({"name": f"{name}_SOURCE", "uri": url, "digest": {"sha256": sha.group(1)}})
    return images + sources


def build_statement(*, policy: dict[str, Any], image_key: str, manifest_digest: str, commit: str,
                    dockerfile_text: str, builder_id: str, run_id: str, started: str, finished: str,
                    byproducts: list[dict[str, Any]], test_run: dict[str, str]) -> dict[str, Any]:
    if not DIGEST.match(manifest_digest) or not COMMIT.match(commit):
        raise ReleaseError("provenance_input_invalid")
    image = policy["images"][image_key]
    source = policy["source"]
    return {
        "_type": STATEMENT_TYPE,
        "subject": [{"name": image["name"], "digest": {"sha256": manifest_digest[7:]}}],
        "predicateType": PREDICATE_TYPE,
        "predicate": {
            "buildDefinition": {
                "buildType": policy["required_evidence"]["build_type"],
                "externalParameters": {
                    "source": {"repository": source["repository"], "ref": f"refs/heads/{source['protected_branch']}", "commit": commit},
                    "dockerfile": image["dockerfile"],
                    "context": image["context"],
                    "target": image["target"],
                    "platform": image["platform"],
                },
                "internalParameters": {
                    "builder_image": policy["tools"]["buildkit"],
                    "exporter": "oci-archive",
                    "build_cache": "none (fresh builder, --no-cache)",
                },
                "resolvedDependencies": [
                    {"uri": f"git+{source['repository']}@refs/heads/{source['protected_branch']}", "digest": {"gitCommit": commit}},
                    *base_images(dockerfile_text),
                ],
            },
            "runDetails": {
                "builder": {"id": builder_id},
                "metadata": {"invocationId": run_id, "startedOn": started, "finishedOn": finished},
                "byproducts": [*byproducts, {"name": "complete-regression", "annotations": test_run}],
            },
        },
    }


def verify_statement(statement: Any, *, policy: dict[str, Any], image_key: str, manifest_digest: str, commit: str,
                     run_id: str, byproduct_digests: dict[str, str], dockerfile_text: str | None) -> list[str]:
    if not isinstance(statement, dict):
        return ["provenance_malformed"]
    errors: list[str] = []
    image = policy["images"][image_key]
    try:
        if statement.get("_type") != STATEMENT_TYPE or statement.get("predicateType") != PREDICATE_TYPE:
            errors.append("provenance_type_invalid")
        subject = statement["subject"]
        if len(subject) != 1 or subject[0]["name"] != image["name"] or subject[0]["digest"] != {"sha256": manifest_digest.removeprefix("sha256:")}:
            errors.append("provenance_subject_mismatch")
        definition = statement["predicate"]["buildDefinition"]
        run = statement["predicate"]["runDetails"]
        if definition["buildType"] != policy["required_evidence"]["build_type"]:
            errors.append("provenance_build_type_invalid")
        params = definition["externalParameters"]
        expected_source = {"repository": policy["source"]["repository"], "ref": f"refs/heads/{policy['source']['protected_branch']}", "commit": commit}
        if params["source"] != expected_source:
            errors.append("provenance_source_mismatch")
        if [params[k] for k in ("dockerfile", "context", "target", "platform")] != [image[k] for k in ("dockerfile", "context", "target", "platform")]:
            errors.append("provenance_build_parameters_mismatch")
        deps = definition["resolvedDependencies"]
        if not any(d.get("digest") == {"gitCommit": commit} for d in deps):
            errors.append("provenance_source_dependency_missing")
        if dockerfile_text is not None and [d for d in deps if "gitCommit" not in d.get("digest", {})] != base_images(dockerfile_text):
            errors.append("provenance_base_images_mismatch")
        if run["metadata"]["invocationId"] != run_id:
            errors.append("provenance_run_mismatch")
        recorded = {b["name"]: b.get("digest", {}).get("sha256") for b in run["byproducts"] if "digest" in b}
        for name, digest in byproduct_digests.items():
            if recorded.get(name) != digest:
                errors.append(f"provenance_byproduct_mismatch_{re.sub(r'[^a-z0-9]+', '_', name.lower()).strip('_')}")
    except (KeyError, TypeError, IndexError, AttributeError):
        return ["provenance_malformed"]

    text = json.dumps(statement)
    if re.search(r"slsa[_ -]?level|\"level\"", text, re.I):
        errors.append("provenance_claims_a_slsa_level")
    if any(c in text for c in CANARIES) or any(p.search(text.encode()) for p in SHAPES.values()):
        errors.append("provenance_contains_secret")
    return errors


def dockerfile_at(repo: Path, commit: str, path: str) -> str:
    from .util import run

    return run(["git", "-C", str(repo), "show", f"{commit}:{path}"], code="source_unavailable").stdout.decode()
