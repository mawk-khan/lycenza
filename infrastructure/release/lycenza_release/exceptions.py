"""Vulnerability exceptions (ADR 0052 section 3.8), validated fail-closed.

The tooling validates the RECORD -- structure, exact identifiers, approval
reference, dates and the maximum window -- and never decides whether a risk
is acceptable (that is a human security decision). Versions are exact only:
version ranges are refused because version ordering differs between
ecosystems (dpkg, PEP 440, SemVer) and a mis-ordered range would silently
widen an exception.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from pathlib import Path
from typing import Any

from .schema import SchemaError, validate
from .util import RELEASE_DIR, ReleaseError, load_json

EXCEPTIONS_FILE = RELEASE_DIR / "vulnerability-exceptions.json"
EXCEPTIONS_SCHEMA = RELEASE_DIR / "schema" / "vulnerability-exceptions.schema.json"

WILDCARDS = set("*?%[]")


@dataclass(frozen=True)
class VulnerabilityException:
    id: str
    status: str
    image: str
    package: str
    version: str
    severity: str
    approved_by: str
    created: date
    expires: date

    def matches(self, image: str, vuln_ids: set[str], package: str, version: str, severity: str) -> bool:
        return (
            self.image == image
            and self.id in vuln_ids
            and self.package == package
            and self.version == version
            and self.severity == severity
        )


def validate_exceptions(document: Any, policy: dict[str, Any], today: date,
                        schema: dict[str, Any] | None = None) -> tuple[list[VulnerabilityException], list[str]]:
    """Return (valid exceptions, errors). ANY error fails the release: callers must not use a partial list."""
    schema = schema if schema is not None else load_json(EXCEPTIONS_SCHEMA)
    try:
        errors = validate(document, schema)
    except SchemaError as exc:
        return [], [f"schema: {exc}"]
    if errors:
        return [], errors

    limits = policy["vulnerability_policy"]["exception_max_days"]
    valid: list[VulnerabilityException] = []
    seen: set[tuple[str, str, str, str]] = set()

    for index, entry in enumerate(document["exceptions"]):
        where = f"$.exceptions[{index}]"
        if any(ch in WILDCARDS for field in ("id", "package", "version") for ch in entry[field]):
            errors.append(f"{where}: wildcards are not permitted")
            continue
        created = date.fromisoformat(entry["created"])
        expires = date.fromisoformat(entry["expires"])
        window = (expires - created).days
        maximum = limits.get(entry["severity"], limits["other"])
        if created > today:
            errors.append(f"{where}: created in the future")
        if expires <= created:
            errors.append(f"{where}: expires on or before its creation")
        elif window > maximum:
            errors.append(f"{where}: {entry['severity']} exception exceeds {maximum} days")
        if expires <= today:
            errors.append(f"{where}: expired")
        key = (entry["id"], entry["image"], entry["package"], entry["version"])
        if key in seen:
            errors.append(f"{where}: duplicate exception")
        seen.add(key)
        valid.append(VulnerabilityException(
            id=entry["id"], status=entry["status"], image=entry["image"], package=entry["package"],
            version=entry["version"], severity=entry["severity"], approved_by=entry["approved_by"],
            created=created, expires=expires,
        ))

    return (valid, errors) if not errors else ([], errors)


def load_exceptions(policy: dict[str, Any], today: date, path: Path | None = None) -> list[VulnerabilityException]:
    valid, errors = validate_exceptions(load_json(path or EXCEPTIONS_FILE), policy, today)
    if errors:
        raise ReleaseError("exceptions_invalid", "; ".join(errors[:5]))
    return valid
