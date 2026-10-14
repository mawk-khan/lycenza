"""The aggregate `CI gate` decision (ADR 0072 section 6; E03.1).

Input: the workflow's `needs` context (`toJSON(needs)`): every job's result
and the classifier's outputs. The gate passes only when

- the classifier itself succeeded and produced a well-formed lane list that
  contains every always-required lane;
- every lane job the classification requires ended `success`;
- every lane job it does not require ended `skipped` -- the one legitimate
  skip, decided centrally by the classifier, never by a job itself;
- the set of lane jobs is exactly the expected one (a job missing from
  `needs`, or an unexpected one, fails closed).

`cancelled`, `failure`, a missing result, or a required job that was skipped
all fail the gate.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field

from .classify import ALWAYS, LANES

CLASSIFIER_JOB = "classify"
# Workflow job id -> the lane it implements (.github/workflows/ci.yml).
JOB_LANES: dict[str, str] = {
    "secrets-scan": "secrets",
    "supply-chain": "supply_chain",
    "integrity": "integrity",
    "production-images": "images",
    # CI sharding Option A (ADR 0072 section 31): the `platform` lane is three
    # jobs; `platform` is the shard matrix, whose aggregate result is
    # `success` only when every leg succeeded.
    "platform-static": "platform",
    "platform": "platform",
    "platform-coverage": "platform",
    "docs-guards": "docs",
    "ai-service": "gateway",
    "shared-types": "shared_types",
    "mobile": "mobile",
}


@dataclass
class Verdict:
    passed: bool
    lanes: list[str] = field(default_factory=list)
    problems: list[str] = field(default_factory=list)
    rows: list[tuple[str, str, bool, str]] = field(default_factory=list)


def decide(needs: dict) -> Verdict:
    verdict = Verdict(passed=False)
    if set(JOB_LANES.values()) != set(LANES):
        verdict.problems.append("internal: the job/lane map does not cover exactly the classifier's lanes")
        return verdict

    classifier = needs.get(CLASSIFIER_JOB)
    if not isinstance(classifier, dict) or classifier.get("result") != "success":
        result = classifier.get("result") if isinstance(classifier, dict) else "absent"
        verdict.problems.append(f"classifier did not succeed (result: {result})")
        return verdict
    try:
        lanes = json.loads((classifier.get("outputs") or {}).get("lanes", ""))
    except (TypeError, ValueError):
        verdict.problems.append("classifier output `lanes` is not JSON")
        return verdict
    if not isinstance(lanes, list) or not all(isinstance(lane, str) for lane in lanes):
        verdict.problems.append("classifier output `lanes` is not a list of names")
        return verdict
    unknown = sorted(set(lanes) - set(LANES))
    if unknown:
        verdict.problems.append(f"classifier named unknown lane(s): {', '.join(unknown)}")
    missing_always = sorted(ALWAYS - set(lanes))
    if missing_always:
        verdict.problems.append(f"classifier dropped always-required lane(s): {', '.join(missing_always)}")
    verdict.lanes = [lane for lane in LANES if lane in lanes]

    jobs = {name: value for name, value in needs.items() if name != CLASSIFIER_JOB}
    unexpected = sorted(set(jobs) - set(JOB_LANES))
    absent = sorted(set(JOB_LANES) - set(jobs))
    if unexpected:
        verdict.problems.append(f"unexpected job(s) in needs: {', '.join(unexpected)}")
    if absent:
        verdict.problems.append(f"lane job(s) missing from needs: {', '.join(absent)}")

    for job, lane in JOB_LANES.items():
        if job not in jobs:
            continue
        result = (jobs[job] or {}).get("result") if isinstance(jobs[job], dict) else None
        required = lane in lanes
        expected = "success" if required else "skipped"
        ok = result == expected
        verdict.rows.append((job, lane, required, str(result)))
        if not ok:
            reason = "required" if required else "not required (must be skipped)"
            verdict.problems.append(f"{job} ({lane}, {reason}): {result}")

    verdict.passed = not verdict.problems
    return verdict


def render(verdict: Verdict) -> str:
    lines = ["| Job | Lane | Required | Result |", "|---|---|---|---|"]
    for job, lane, required, result in verdict.rows:
        lines.append(f"| {job} | {lane} | {'yes' if required else 'no'} | {result} |")
    lines.append("")
    lines.append("**CI gate: PASS**" if verdict.passed else "**CI gate: FAIL**")
    lines.extend(f"- {problem}" for problem in verdict.problems)
    return "\n".join(lines)
