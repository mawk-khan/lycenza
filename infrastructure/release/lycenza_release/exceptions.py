"""Vulnerability exceptions (ADR 0052 section 3.8), validated fail-closed.

The tooling validates the RECORD -- structure, exact identifiers, approval
reference, dates and the maximum window -- and never decides whether a risk
is acceptable (that is a human security decision). Versions are exact only:
version ranges are refused because version ordering differs between
ecosystems (dpkg, PEP 440, SemVer) and a mis-ordered range would silently
widen an exception.

Phase 0O.6F: every exception names (`approved_by`) an approval recorded in the
same file's `approvals`, and that approval must point at a committed decision
record that names it and the advisory. An exception may not go beyond its
approval: the advisory, the image/package and its exact status, the approved
maximum duration (never above the policy maximum) and the approval's
conditions must all match. A conditional exception is only a candidate here --
verify-artifact applies it only with the condition's evidence.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from datetime import date
from pathlib import Path
from typing import Any

from .schema import SchemaError, validate
from .util import RELEASE_DIR, REPO_ROOT, ReleaseError, load_json

EXCEPTIONS_FILE = RELEASE_DIR / "vulnerability-exceptions.json"
EXCEPTIONS_SCHEMA = RELEASE_DIR / "schema" / "vulnerability-exceptions.schema.json"

WILDCARDS = set("*?%[]")
APPROVAL_REF = re.compile(r"^[A-Z][A-Z0-9]*(-[A-Z0-9]+)+$")
ADVISORY_ID = re.compile(r"^(CVE-[0-9]{4}-[0-9]{4,}|GHSA(-[23456789cfghjmpqrvwx]{4}){3}|PYSEC-[0-9]{4}-[0-9]+)$")


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
    conditions: tuple[str, ...] = ()

    def matches(self, image: str, vuln_ids: set[str], package: str, version: str, severity: str) -> bool:
        return (
            self.image == image
            and self.id in vuln_ids
            and self.package == package
            and self.version == version
            and self.severity == severity
        )


def _approval_errors(approvals: dict[str, Any], repo: Path) -> list[str]:
    """Each approval names a committed decision record that states its reference and every advisory it approves."""
    errors = []
    for ref, approval in approvals.items():
        where = f"$.approvals.{ref}"
        if not APPROVAL_REF.match(ref):
            errors.append(f"{where}: malformed approval reference")
            continue
        record = Path(approval["record"])
        path = (repo / record).resolve()
        if record.is_absolute() or ".." in record.parts or not path.is_relative_to(repo.resolve()) or not path.is_file():
            errors.append(f"{where}: decision record not found in the repository")
            continue
        text = path.read_text(encoding="utf-8")
        if ref not in text:
            errors.append(f"{where}: decision record does not name the approval")
        for advisory in approval["advisories"]:
            if not ADVISORY_ID.match(advisory):
                errors.append(f"{where}: malformed advisory id")
            elif advisory not in text:
                errors.append(f"{where}: decision record does not name {advisory}")
    return errors


def _beyond_approval(entry: dict[str, Any], approval: dict[str, Any] | None, created: date, window: int) -> list[str]:
    """Where the exception goes beyond what its approval grants (empty when it stays within it)."""
    if approval is None:
        return ["approval reference not recorded in approvals"]
    advisory = approval["advisories"].get(entry["id"])
    if advisory is None:
        return [f"{entry['id']} is not approved by {entry['approved_by']}"]
    errors = []
    status = advisory["packages"].get(entry["image"], {}).get(entry["package"])
    if status is None:
        errors.append(f"{entry['image']}/{entry['package']} is not approved for {entry['id']}")
    elif status != entry["status"]:
        errors.append(f"status {entry['status']} differs from the approved {status}")
    if window > advisory["max_days"]:
        errors.append(f"exceeds the approved {advisory['max_days']} days")
    if created < date.fromisoformat(approval["decided"]):
        errors.append("created before the approval was decided")
    if sorted(entry.get("conditions", [])) != sorted(advisory["conditions"]):
        errors.append("conditions differ from the approval's conditions")
    return errors


def validate_exceptions(document: Any, policy: dict[str, Any], today: date,
                        schema: dict[str, Any] | None = None,
                        repo: Path = REPO_ROOT) -> tuple[list[VulnerabilityException], list[str]]:
    """Return (valid exceptions, errors). ANY error fails the release: callers must not use a partial list."""
    schema = schema if schema is not None else load_json(EXCEPTIONS_SCHEMA)
    try:
        errors = validate(document, schema)
    except SchemaError as exc:
        return [], [f"schema: {exc}"]
    if errors:
        return [], errors

    limits = policy["vulnerability_policy"]["exception_max_days"]
    approvals = document.get("approvals", {})
    errors += _approval_errors(approvals, repo)
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
        errors += [f"{where}: {e}" for e in _beyond_approval(entry, approvals.get(entry["approved_by"]), created, window)]
        key = (entry["id"], entry["image"], entry["package"], entry["version"])
        if key in seen:
            errors.append(f"{where}: duplicate exception")
        seen.add(key)
        valid.append(VulnerabilityException(
            id=entry["id"], status=entry["status"], image=entry["image"], package=entry["package"],
            version=entry["version"], severity=entry["severity"], approved_by=entry["approved_by"],
            created=created, expires=expires, conditions=tuple(sorted(entry.get("conditions", []))),
        ))

    return (valid, errors) if not errors else ([], errors)


def load_exceptions(policy: dict[str, Any], today: date, path: Path | None = None) -> list[VulnerabilityException]:
    valid, errors = validate_exceptions(load_json(path or EXCEPTIONS_FILE), policy, today)
    if errors:
        raise ReleaseError("exceptions_invalid", "; ".join(errors[:5]))
    return valid
