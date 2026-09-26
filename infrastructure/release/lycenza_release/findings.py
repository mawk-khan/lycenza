"""Normalize UNMODIFIED scanner/audit reports into one finding shape.

Reports are read, never rewritten: the evidence keeps each tool's original
output, and the policy evaluator (evaluate.py) is the only place that
decides what blocks. Normalization only maps each tool's vocabulary onto:
severity (critical/high/medium/low/negligible/unknown), fix state
(fixed/not-fixed/wont-fix/unknown) and exact package/version.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from .util import ReleaseError

SEVERITIES = ("critical", "high", "medium", "low", "negligible", "unknown")

_NPM_SEVERITY = {"critical": "critical", "high": "high", "moderate": "medium", "low": "low", "info": "low"}
_GHSA = re.compile(r"(GHSA(?:-[23456789cfghjmpqrvwx]{4}){3})")


@dataclass(frozen=True)
class Finding:
    source: str
    image: str
    id: str
    package: str
    version: str
    severity: str
    fix_state: str
    rated: bool = True
    aliases: frozenset[str] = field(default_factory=frozenset)

    @property
    def ids(self) -> set[str]:
        return {self.id, *self.aliases}

    def key(self) -> tuple[str, str, str, str]:
        return (self.image, self.id, self.package, self.version)


def _require(condition: bool, source: str) -> None:
    if not condition:
        raise ReleaseError("report_malformed", source)


def grype(report: Any, image: str) -> list[Finding]:
    _require(isinstance(report, dict) and isinstance(report.get("matches"), list), "grype")
    findings = []
    for match in report["matches"]:
        _require(isinstance(match, dict), "grype")
        vuln, artifact = match.get("vulnerability"), match.get("artifact")
        _require(isinstance(vuln, dict) and isinstance(artifact, dict), "grype")
        severity = str(vuln.get("severity", "")).lower() or "unknown"
        _require(severity in SEVERITIES, "grype")
        state = str((vuln.get("fix") or {}).get("state") or "unknown")
        aliases = frozenset(str(r.get("id")) for r in match.get("relatedVulnerabilities", []) if isinstance(r, dict) and r.get("id"))
        findings.append(Finding(
            source="grype", image=image, id=str(vuln.get("id")), package=str(artifact.get("name")),
            version=str(artifact.get("version")), severity=severity,
            fix_state=state if state in ("fixed", "not-fixed", "wont-fix") else "unknown", aliases=aliases,
        ))
    return unique(findings)


def composer_audit(report: Any, locked_versions: dict[str, str], unrated: str) -> list[Finding]:
    """`composer audit --format=json`; the installed version comes from composer.lock."""
    _require(isinstance(report, dict) and "advisories" in report, "composer")
    advisories = report["advisories"]
    _require(isinstance(advisories, (dict, list)), "composer")
    if isinstance(advisories, list):
        _require(advisories == [], "composer")
        return []
    findings = []
    for package, entries in advisories.items():
        _require(isinstance(entries, (list, dict)), "composer")
        for entry in (entries.values() if isinstance(entries, dict) else entries):
            ids = [entry.get("cve"), entry.get("advisoryId")] + [s.get("remoteId") for s in entry.get("sources", []) if isinstance(s, dict)]
            ids = [str(i) for i in ids if i]
            _require(bool(ids), "composer")
            primary = next((i for i in ids if i.startswith(("CVE-", "GHSA-"))), ids[0])
            severity = str(entry.get("severity") or "").lower()
            rated = severity in SEVERITIES
            findings.append(Finding(
                source="composer", image="app", id=primary, package=package,
                version=locked_versions.get(package, "unknown"),
                severity=severity if rated else unrated,
                # Composer's report carries affected ranges, not fixes: treated
                # as fixable, i.e. a High blocks rather than waiting on an exception.
                fix_state="fixed", rated=rated, aliases=frozenset(ids) - {primary},
            ))
    return findings


def npm_audit(report: Any, locked_versions: dict[str, str]) -> list[Finding]:
    """`npm audit --json` (lockfile v2+): only advisories raised ON a package, not propagation entries."""
    _require(isinstance(report, dict) and isinstance(report.get("vulnerabilities"), dict), "npm")
    findings = []
    for name, entry in report["vulnerabilities"].items():
        _require(isinstance(entry, dict) and isinstance(entry.get("via"), list), "npm")
        fixable = bool(entry.get("fixAvailable"))
        for via in entry["via"]:
            if isinstance(via, str):
                continue
            _require(isinstance(via, dict), "npm")
            ghsa = _GHSA.search(str(via.get("url", "")))
            advisory = ghsa.group(1) if ghsa else f"npm-{via.get('source')}"
            severity = _NPM_SEVERITY.get(str(via.get("severity", "")).lower())
            _require(severity is not None, "npm")
            findings.append(Finding(
                source="npm", image="app", id=advisory, package=str(via.get("name") or name),
                version=locked_versions.get(str(via.get("name") or name), "unknown"), severity=str(severity),
                fix_state="fixed" if fixable else "not-fixed",
            ))
    return findings


def pip_audit(report: Any, unrated: str) -> list[Finding]:
    """`pip-audit --format json`: advisories carry no severity, so the policy's unrated severity applies."""
    _require(isinstance(report, dict) and isinstance(report.get("dependencies"), list), "pip")
    findings = []
    for dependency in report["dependencies"]:
        _require(isinstance(dependency, dict), "pip")
        if "skip_reason" in dependency:
            # A dependency pip-audit could not audit is itself a failure: fail closed.
            raise ReleaseError("audit_incomplete", "pip")
        for vuln in dependency.get("vulns", []):
            findings.append(Finding(
                source="pip", image="ai-gateway", id=str(vuln.get("id")), package=str(dependency.get("name")),
                version=str(dependency.get("version")), severity=unrated,
                fix_state="fixed" if vuln.get("fix_versions") else "not-fixed", rated=False,
                aliases=frozenset(str(a) for a in vuln.get("aliases", [])),
            ))
    return unique(findings)


def unique(findings: list[Finding]) -> list[Finding]:
    """Drop exact duplicates (a tool may list one advisory once per data source)."""
    seen: set[tuple[str, str, str, str, str]] = set()
    result = []
    for finding in findings:
        key = (finding.source, *finding.key())
        if key not in seen:
            seen.add(key)
            result.append(finding)
    return result


def composer_locked_versions(lock: Any) -> dict[str, str]:
    _require(isinstance(lock, dict) and isinstance(lock.get("packages"), list), "composer.lock")
    return {str(p["name"]): str(p["version"]).removeprefix("v") for p in lock["packages"]}


def npm_locked_versions(lock: Any) -> dict[str, str]:
    _require(isinstance(lock, dict) and isinstance(lock.get("packages"), dict), "package-lock.json")
    versions: dict[str, str] = {}
    for path, meta in lock["packages"].items():
        if path.startswith("node_modules/") and isinstance(meta, dict) and "version" in meta:
            versions.setdefault(path.rsplit("node_modules/", 1)[1], str(meta["version"]))
    return versions
