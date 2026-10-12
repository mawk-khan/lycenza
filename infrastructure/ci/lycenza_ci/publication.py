"""Exact-SHA publication verdict (ADR 0072 sections 3, 13, 14, 20; E03.1).

Answers one question immediately before `main` is advanced: may THIS commit
become the `main` tip now? It is publishable only when

1. a `CI gate` check run exists for exactly this SHA, emitted by the GitHub
   Actions app (id 15368) -- a run from any other app/source is reported and
   never counts;
2. the newest Actions-owned `CI gate` run for the SHA is completed/success;
3. that run completed no more than `max_age_hours` ago (default 72): a green
   check on a SHA never expires on GitHub, so freshness is enforced here;
4. the vulnerability-exception decision AT THE CANDIDATE (E16) is still valid
   today (UTC) and was valid when the gate ran -- an expired decision means
   the supply-chain result is stale, whatever GitHub shows;
5. the current remote `main` is a strict ancestor of the candidate (a
   fast-forward). If `main` moved, the candidate must be rebased and
   re-checked -- another SHA's checks are never reused.

This is the publication wrapper's decision. It complements, and never
replaces, the remote `main-protection` ruleset (E03.2); a local check can be
skipped by anyone with push access, the ruleset cannot.
"""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field
from datetime import UTC, date, datetime, timedelta

from .contract import GATE_CONTEXT, GITHUB_ACTIONS_APP_ID

DEFAULT_MAX_AGE_HOURS = 72


def parse_time(value: str | None) -> datetime | None:
    if not value:
        return None
    return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(UTC)


def earliest_expiry(exceptions_document: dict) -> date | None:
    """The first date on which any exception record is no longer valid."""
    records = exceptions_document.get("exceptions")
    if not isinstance(records, list):
        raise ValueError("exceptions document has no `exceptions` list")
    expiries = [date.fromisoformat(record["expires"]) for record in records]
    return min(expiries) if expiries else None


@dataclass
class PublicationVerdict:
    candidate: str
    main: str
    publishable: bool = False
    fast_forward: bool = False
    gate: dict | None = None
    foreign_gate_runs: list[dict] = field(default_factory=list)
    age_hours: float | None = None
    e16_earliest_expiry: str | None = None  # the first day the decision is NOT valid
    e16_last_valid_day: str | None = None   # the day before: the last day publication may pass
    problems: list[str] = field(default_factory=list)

    def to_json(self) -> str:
        return json.dumps(asdict(self), indent=2, sort_keys=True)


def decide(
    *,
    candidate: str,
    main: str,
    main_is_ancestor: bool,
    check_runs: list[dict],
    exceptions_document: dict | None,
    now: datetime,
    max_age_hours: float = DEFAULT_MAX_AGE_HOURS,
) -> PublicationVerdict:
    verdict = PublicationVerdict(candidate=candidate, main=main)

    if candidate == main:
        verdict.problems.append("nothing to publish: the candidate already is the remote main tip")
    elif not main_is_ancestor:
        verdict.problems.append("not a fast-forward: remote main moved or diverged -- rebase onto it and re-check the new SHA")
    else:
        verdict.fast_forward = True

    gate_runs = [run for run in check_runs if run.get("name") == GATE_CONTEXT and run.get("head_sha") == candidate]
    actions_runs = [run for run in gate_runs if (run.get("app") or {}).get("id") == GITHUB_ACTIONS_APP_ID]
    verdict.foreign_gate_runs = [
        {"id": run.get("id"), "app_id": (run.get("app") or {}).get("id"), "app_slug": (run.get("app") or {}).get("slug")}
        for run in gate_runs if run not in actions_runs
    ]
    # A `CI gate` from any other source is reported in foreign_gate_runs and
    # never counted; it neither authorizes nor, by itself, refuses publication.
    if not actions_runs:
        verdict.problems.append(f"no `{GATE_CONTEXT}` check run from GitHub Actions exists for exactly {candidate}")
    else:
        latest = max(actions_runs, key=lambda run: parse_time(run.get("completed_at") or run.get("started_at")) or datetime.min.replace(tzinfo=UTC))
        completed = parse_time(latest.get("completed_at"))
        verdict.gate = {
            "check_run_id": latest.get("id"),
            "status": latest.get("status"),
            "conclusion": latest.get("conclusion"),
            "completed_at": latest.get("completed_at"),
            "app_id": (latest.get("app") or {}).get("id"),
            "app_slug": (latest.get("app") or {}).get("slug"),
        }
        if latest.get("status") != "completed" or completed is None:
            verdict.problems.append(f"the newest `{GATE_CONTEXT}` run is not completed (status: {latest.get('status')})")
        elif latest.get("conclusion") != "success":
            verdict.problems.append(f"the newest `{GATE_CONTEXT}` run concluded {latest.get('conclusion')}")
        else:
            verdict.age_hours = round((now - completed).total_seconds() / 3600, 2)
            if verdict.age_hours > max_age_hours:
                verdict.problems.append(f"the `{GATE_CONTEXT}` result is {verdict.age_hours} h old (limit {max_age_hours} h): re-run CI on the candidate")

    if exceptions_document is None:
        verdict.problems.append("the candidate's vulnerability-exceptions file could not be read")
    else:
        try:
            expiry = earliest_expiry(exceptions_document)
        except (KeyError, TypeError, ValueError) as error:
            verdict.problems.append(f"the candidate's vulnerability-exceptions file is unreadable: {error}")
        else:
            verdict.e16_earliest_expiry = expiry.isoformat() if expiry else None
            verdict.e16_last_valid_day = (expiry - timedelta(days=1)).isoformat() if expiry else None
            if expiry is not None:
                if now.date() >= expiry:
                    verdict.problems.append(f"E16: the exception decision at the candidate expired on {expiry} -- a fresh decision and a new CI run are required")
                completed = parse_time((verdict.gate or {}).get("completed_at"))
                if completed is not None and completed.date() >= expiry:
                    verdict.problems.append(f"E16: the `{GATE_CONTEXT}` run completed on or after the decision expired ({expiry})")

    verdict.publishable = not verdict.problems
    return verdict
