"""The ONE vulnerability policy evaluator (ADR 0052 section 3.7).

Every threshold decision lives here -- qualify, verify-artifact and the
scheduled re-scan all call evaluate(); nothing else interprets severities.

- CRITICAL blocks, always.
- HIGH blocks when a fix is available.
- HIGH without a fix blocks unless a valid, unexpired exception matches.
- MEDIUM / LOW / NEGLIGIBLE / UNKNOWN are recorded, never blocking here.
- An exception matches only on the exact image, advisory id (or alias),
  package, version AND reported severity; a scanner that raises the severity
  therefore invalidates the exception rather than being silently covered.
"""

from __future__ import annotations

from collections import Counter
from collections.abc import Iterable
from typing import Any

from .exceptions import VulnerabilityException
from .findings import SEVERITIES, Finding


def _decision(finding: Finding) -> str | None:
    """The blocking reason, or None when the finding is only recorded."""
    if finding.severity == "critical":
        return "critical"
    if finding.severity == "high":
        return "high_fix_available" if finding.fix_state == "fixed" else "high_requires_exception"
    return None


def evaluate(findings: Iterable[Finding], exceptions: list[VulnerabilityException]) -> dict[str, Any]:
    findings = list(findings)
    used: set[int] = set()
    blocking: list[dict[str, Any]] = []
    excepted: list[dict[str, Any]] = []
    counts: Counter[str] = Counter()

    for finding in findings:
        counts[f"{finding.image}:{finding.severity}"] += 1
        reason = _decision(finding)
        if reason is None:
            continue
        match = next((i for i, e in enumerate(exceptions)
                      if e.matches(finding.image, finding.ids, finding.package, finding.version, finding.severity)), None)
        record = {
            "source": finding.source, "image": finding.image, "id": finding.id, "package": finding.package,
            "version": finding.version, "severity": finding.severity, "fix_state": finding.fix_state,
            "severity_rated_by_source": finding.rated,
        }
        if match is not None:
            used.add(match)
            excepted.append({**record, "exception": exceptions[match].id, "exception_status": exceptions[match].status,
                             "exception_expires": exceptions[match].expires.isoformat()})
        else:
            blocking.append({**record, "reason": reason})

    unused = [{"id": e.id, "image": e.image, "package": e.package, "version": e.version}
              for i, e in enumerate(exceptions) if i not in used]

    return {
        "verdict": "FAIL" if blocking else "PASS",
        "counts": {k: counts[k] for k in sorted(counts)},
        "severities": list(SEVERITIES),
        "blocking": sorted(blocking, key=lambda r: (r["image"], r["severity"] != "critical", r["id"], r["package"])),
        "excepted": excepted,
        "unused_exceptions": unused,
    }
