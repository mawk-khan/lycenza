"""The artifact policy manifest (ADR 0052 section 3.12) -- loaded and validated fail-closed."""

from __future__ import annotations

from pathlib import Path
from typing import Any

from .schema import SchemaError, validate
from .util import RELEASE_DIR, ReleaseError, load_json

POLICY_FILE = RELEASE_DIR / "artifact-policy.json"
POLICY_SCHEMA = RELEASE_DIR / "schema" / "artifact-policy.schema.json"

IMAGE_KEYS = ("app", "ai-gateway")


def validate_policy(policy: Any, schema: dict[str, Any] | None = None) -> list[str]:
    schema = schema if schema is not None else load_json(POLICY_SCHEMA)
    try:
        errors = validate(policy, schema)
    except SchemaError as exc:
        return [f"schema: {exc}"]
    if errors:
        return errors

    signing = policy["signing"]
    custody = signing["custody"]
    if custody == "unconfigured" and any(signing[k] for k in ("public_key_ref", "certificate_identity", "certificate_oidc_issuer")):
        errors.append("$.signing: an unconfigured custody names no identity")
    if custody == "external-key" and (not signing["public_key_ref"] or signing["certificate_identity"] or signing["certificate_oidc_issuer"]):
        errors.append("$.signing: external-key custody names exactly a public key reference")
    if custody == "keyless" and (signing["public_key_ref"] or not signing["certificate_identity"] or not signing["certificate_oidc_issuer"]):
        errors.append("$.signing: keyless custody names exactly a certificate identity and OIDC issuer")
    return errors


def load_policy(path: Path = POLICY_FILE) -> dict[str, Any]:
    policy = load_json(path)
    errors = validate_policy(policy)
    if errors:
        raise ReleaseError("policy_invalid", "; ".join(errors[:5]))
    return policy
