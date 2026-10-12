"""Remote-protection drift detection (ADR 0072 section 19; E03.1).

Compares what GitHub reports for `main` with the `main-protection` contract
(ADR 0072 section 5). The expected programme state lives in
infrastructure/ci/main-protection-state.json:

- `awaiting-activation` (E03.1, before E03.2): report what is observed and
  pass -- protection is not active yet by design, so its absence is not drift;
- `active` (set by E03.2 once the ruleset is live): every contracted property
  must be observed, or the check fails. That includes the pinned ruleset id
  (a deleted and recreated ruleset is drift: it was absent in between), the
  status-check parameters, and no rule type beyond the contract (an added
  `pull_request` rule is drift too, until the contract itself changes).

Detection is never protection: it notices a removed or weakened ruleset
after the fact (section 18).

What the public API shows: `GET /repos/{repo}/rules/branches/main` (the
effective rules and the ruleset each comes from) and `GET /repos/{repo}/
rulesets[/{id}]`. A ruleset's `bypass_actors` may be withheld from callers
without admin access; when it is absent the bypass check is reported as
UNVERIFIED, never as passing.
"""

from __future__ import annotations

from dataclasses import dataclass, field

STATES = ("awaiting-activation", "active")


@dataclass
class DriftReport:
    state: str
    ok: bool = True
    findings: list[str] = field(default_factory=list)
    unverified: list[str] = field(default_factory=list)
    observed: dict = field(default_factory=dict)


def _status_checks(rule: dict) -> list[dict]:
    return list(((rule.get("parameters") or {}).get("required_status_checks")) or [])


def _check_parameters(rule: dict) -> dict:
    parameters = dict(rule.get("parameters") or {})
    parameters.pop("required_status_checks", None)
    return parameters


def assess(state: str, contract: dict, effective_rules: list[dict], rulesets: list[dict],
           pinned_id: int | None = None) -> DriftReport:
    if state not in STATES:
        report = DriftReport(state=state, ok=False)
        report.findings.append(f"unknown expected state {state!r} (expected one of {', '.join(STATES)})")
        return report
    report = DriftReport(state=state)
    by_type: dict[str, list[dict]] = {}
    for rule in effective_rules:
        by_type.setdefault(rule.get("type", "?"), []).append(rule)
    named = [ruleset for ruleset in rulesets if ruleset.get("name") == contract["name"]]
    report.observed = {
        "effective_rule_types": sorted(by_type),
        "rulesets": [{"id": r.get("id"), "name": r.get("name"), "enforcement": r.get("enforcement")} for r in rulesets],
    }

    findings: list[str] = []
    if len(named) != 1:
        findings.append(f"expected exactly one ruleset named {contract['name']!r}, found {len(named)}")
    else:
        ruleset = named[0]
        if pinned_id is not None and ruleset.get("id") != pinned_id:
            findings.append(f"ruleset id is {ruleset.get('id')}, pinned {pinned_id} (deleted and recreated?)")
        if ruleset.get("enforcement") != contract["enforcement"]:
            findings.append(f"ruleset enforcement is {ruleset.get('enforcement')!r}, contract {contract['enforcement']!r}")
        if ruleset.get("target") not in (None, contract["target"]):
            findings.append(f"ruleset target is {ruleset.get('target')!r}, contract {contract['target']!r}")
        include = (((ruleset.get("conditions") or {}).get("ref_name") or {}).get("include"))
        if include is not None and include != contract["conditions"]["ref_name"]["include"]:
            findings.append(f"ruleset applies to {include}, contract {contract['conditions']['ref_name']['include']}")
        if "bypass_actors" in ruleset:
            if ruleset["bypass_actors"] != contract["bypass_actors"]:
                findings.append(f"unexpected bypass actor(s): {ruleset['bypass_actors']}")
        else:
            report.unverified.append("bypass actors not visible to this caller (needs an admin-scoped read)")

    for rule in contract["rules"]:
        kind = rule["type"]
        if kind not in by_type:
            findings.append(f"effective rules on main lack {kind!r}")
            continue
        if kind == "required_status_checks":
            wanted = _status_checks(rule)
            present = [check for live in by_type[kind] for check in _status_checks(live)]
            for check in wanted:
                if check not in present:
                    findings.append(f"required status check {check} is not enforced on main (observed {present})")
            for live in by_type[kind]:
                if _check_parameters(live) != _check_parameters(rule):
                    findings.append(f"status-check parameters {_check_parameters(live)}, contract {_check_parameters(rule)}")
    extra = sorted(set(by_type) - {rule["type"] for rule in contract["rules"]})
    if extra:
        report.observed["rule_types_beyond_contract"] = extra
        findings.append(f"rule type(s) beyond the contract on main: {', '.join(extra)}")

    if state == "awaiting-activation":
        report.observed["contract_findings_while_awaiting_activation"] = findings
        return report
    report.findings = findings
    report.ok = not findings
    return report
