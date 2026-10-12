"""`infrastructure/ci/lycenza-ci <command>` (ADR 0072; E03.1). See infrastructure/ci/README.md.

  classify            choose the candidate's lanes (CI: writes $GITHUB_OUTPUT)
  gate                decide `CI gate` from the workflow's `needs` (env NEEDS_JSON)
  publication-check   may this exact SHA become `main` now? (JSON verdict; --push to fast-forward)
  drift               compare GitHub's live `main` protection with the contract
  junit-digest        PHPUnit selection digest + per-class assertions (parity evidence)
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import UTC, datetime
from pathlib import Path

from . import classify as classifier
from . import drift as drift_mod
from . import gate as gate_mod
from . import junit
from . import publication
from .contract import GATE_CONTEXT, REPO_ROOT, load_ruleset

STATE_FILE = REPO_ROOT / "infrastructure/ci/main-protection-state.json"
EXCEPTIONS = "infrastructure/release/vulnerability-exceptions.json"


def git(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", "-C", str(REPO_ROOT), *args], capture_output=True, text=True, check=check)


def is_commit(rev: str) -> bool:
    return bool(rev) and set(rev) != {"0"} and git("cat-file", "-e", f"{rev}^{{commit}}", check=False).returncode == 0


def write_output(values: dict[str, str]) -> None:
    target = os.environ.get("GITHUB_OUTPUT")
    if target:
        with open(target, "a", encoding="utf-8") as handle:
            for key, value in values.items():
                handle.write(f"{key}={value}\n")


def write_summary(text: str) -> None:
    target = os.environ.get("GITHUB_STEP_SUMMARY")
    if target:
        with open(target, "a", encoding="utf-8") as handle:
            handle.write(text + "\n")


def annotate(level: str, title: str, message: str) -> None:
    # Annotations are readable through the public check-run API without log access.
    if os.environ.get("GITHUB_ACTIONS") == "true":
        clean = message.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
        print(f"::{level} title={title}::{clean}")


# --- classify --------------------------------------------------------------------------

def resolve_base(event: str, ref: str, before: str, pr_base: str, main_ref: str) -> str | None:
    """The commit the candidate is compared against; None when there is none usable.

    - pull request: the merge base with the PR's base commit;
    - push to main: the previous main tip (`before`), when it is an ancestor;
    - push to any other branch (integration/**): the merge base with main, so a
      candidate is always judged as a whole against main, never only against
      its own previous tip.
    """
    head = git("rev-parse", "HEAD").stdout.strip()
    if event == "pull_request":
        base = git("merge-base", pr_base, head, check=False).stdout.strip() if is_commit(pr_base) else ""
    elif ref == "refs/heads/main":
        ancestor = is_commit(before) and git("merge-base", "--is-ancestor", before, head, check=False).returncode == 0
        base = before if ancestor else ""
    else:
        base = git("merge-base", main_ref, head, check=False).stdout.strip() if is_commit(main_ref) else ""
    return base if base and is_commit(base) else None


def cmd_classify(args: argparse.Namespace) -> int:
    head = git("rev-parse", "HEAD").stdout.strip()
    base = resolve_base(args.event, args.ref, args.before, args.pr_base, args.main_ref)
    if base is None or base == head:
        paths = None if base is None else []
    else:
        paths = classifier.parse_name_status(git("diff", "--name-status", "-z", "-M", base, head).stdout)
    result = classifier.classify(paths)
    lanes = result.ordered_lanes()
    print(f"head {head}; base {base or '(none)'}; {result.reason}")
    for path, klass in result.classes.items():
        print(f"  {klass:13} {path}")
    print("lanes: " + ", ".join(lanes))
    write_output({"lanes": json.dumps(lanes), "base": base or "", "strict": "true" if result.strict else "false"})
    write_summary(f"### Classification\n\n- head `{head}`\n- base `{base or '(none)'}`\n- {result.reason}\n- lanes: `{', '.join(lanes)}`\n")
    annotate("notice", "Classification", f"base {base or '(none)'}; lanes: {', '.join(lanes)}")
    return 0


# --- gate ------------------------------------------------------------------------------

def cmd_gate(args: argparse.Namespace) -> int:
    raw = os.environ.get("NEEDS_JSON", "")
    try:
        needs = json.loads(raw)
    except ValueError:
        print("NEEDS_JSON is not JSON", file=sys.stderr)
        annotate("error", "CI gate", "NEEDS_JSON is not JSON")
        return 1
    verdict = gate_mod.decide(needs if isinstance(needs, dict) else {})
    text = gate_mod.render(verdict)
    print(text)
    write_summary("### CI gate\n\n" + text)
    if verdict.passed:
        annotate("notice", "CI gate", "PASS; lanes: " + ", ".join(verdict.lanes))
        return 0
    annotate("error", "CI gate", "FAIL: " + "; ".join(verdict.problems))
    return 1


# --- GitHub API (read-only) ------------------------------------------------------------

def repo_slug(remote: str) -> str:
    url = git("remote", "get-url", remote).stdout.strip()
    match = re.search(r"[:/]([^/:]+/[^/]+?)(?:\.git)?/?$", url)
    if not match:
        raise SystemExit(f"cannot derive owner/name from remote {remote!r}; pass --repo")
    return match.group(1)


def api_get(path: str) -> tuple[int, object]:
    request = urllib.request.Request(f"https://api.github.com{path}", headers={
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "lycenza-ci",
    })
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if token:
        request.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status, json.loads(response.read().decode())
    except urllib.error.HTTPError as error:
        body = error.read().decode(errors="replace")
        try:
            return error.code, json.loads(body)
        except ValueError:
            return error.code, body


# --- publication-check ------------------------------------------------------------------

def cmd_publication_check(args: argparse.Namespace) -> int:
    repo = args.repo or repo_slug(args.remote)
    candidate = git("rev-parse", "--verify", f"{args.candidate}^{{commit}}").stdout.strip()
    remote_main = git("ls-remote", args.remote, "refs/heads/main").stdout.split()
    main = remote_main[0] if remote_main else ""
    if main and not is_commit(main):
        git("fetch", "--quiet", args.remote, "main", check=False)
    ancestor = bool(main) and is_commit(main) and git("merge-base", "--is-ancestor", main, candidate, check=False).returncode == 0

    status, body = api_get(f"/repos/{repo}/commits/{candidate}/check-runs?check_name={urllib.parse.quote(GATE_CONTEXT)}&filter=all&per_page=100")
    runs = body.get("check_runs", []) if status == 200 and isinstance(body, dict) else []
    shown = git("show", f"{candidate}:{EXCEPTIONS}", check=False)
    try:
        document = json.loads(shown.stdout) if shown.returncode == 0 else None
    except ValueError:
        document = None

    verdict = publication.decide(candidate=candidate, main=main, main_is_ancestor=ancestor, check_runs=runs,
                                 exceptions_document=document, now=datetime.now(UTC), max_age_hours=args.max_age_hours)
    if status != 200:
        verdict.problems.insert(0, f"GitHub check-runs API answered {status}")
        verdict.publishable = False
    print(verdict.to_json())
    if not verdict.publishable:
        return 1
    if args.push:
        pushed = subprocess.run(["git", "-C", str(REPO_ROOT), "push", args.remote, f"{candidate}:refs/heads/main"])
        if pushed.returncode != 0:
            return pushed.returncode
        after = git("ls-remote", args.remote, "refs/heads/main").stdout.split()
        if not after or after[0] != candidate:
            print(f"remote main is {after[0] if after else '(none)'} after the push, expected {candidate}", file=sys.stderr)
            return 1
        print(f"published: remote main = {candidate}")
    return 0


# --- drift ------------------------------------------------------------------------------

def cmd_drift(args: argparse.Namespace) -> int:
    repo = args.repo or repo_slug(args.remote)
    pinned = json.loads(STATE_FILE.read_text(encoding="utf-8"))
    state = args.state or pinned["expected_state"]
    pinned_id = (pinned.get("ruleset") or {}).get("id") if state == "active" else None
    contract = load_ruleset()
    status, rules = api_get(f"/repos/{repo}/rules/branches/main")
    if status != 200 or not isinstance(rules, list):
        print(f"rules/branches/main answered {status}: {rules}", file=sys.stderr)
        return 1
    status, listing = api_get(f"/repos/{repo}/rulesets?includes_parents=true")
    if status != 200 or not isinstance(listing, list):
        print(f"rulesets answered {status}: {listing}", file=sys.stderr)
        return 1
    detailed = []
    for summary in listing:
        detail_status, detail = api_get(f"/repos/{repo}/rulesets/{summary['id']}")
        detailed.append(detail if detail_status == 200 and isinstance(detail, dict) else summary)
    report = drift_mod.assess(state, contract, rules, detailed, pinned_id)
    print(json.dumps({"repo": repo, "state": report.state, "ok": report.ok, "findings": report.findings,
                      "unverified": report.unverified, "observed": report.observed}, indent=2, sort_keys=True))
    level = "notice" if report.ok else "error"
    annotate(level, "Protection drift", f"state {report.state}; ok {report.ok}; findings {report.findings}; unverified {report.unverified}")
    return 0 if report.ok else 1


def cmd_junit_digest(args: argparse.Namespace) -> int:
    if args.compare:
        left, right = (json.loads(Path(path).read_text(encoding="utf-8")) for path in args.compare)
        print(json.dumps(junit.compare(left, right), indent=2, sort_keys=True))
        return 0
    path = Path(args.report)
    if not path.is_file():
        annotate("error", "PHPUnit digest", f"no JUnit report at {path}")
        print(f"no JUnit report at {path}", file=sys.stderr)
        return 1
    value = junit.digest(path)
    print(junit.dumps(value))
    if args.out:
        Path(args.out).write_text(junit.dumps(value), encoding="utf-8")
    # GitHub truncates an annotation message at 4,096 characters: the header
    # (counts, outcomes, selection hash) and the compressed per-class table
    # are published separately, the table in numbered chunks.
    table = value["per_class_zlib_b64"]
    header = {key: item for key, item in value.items() if key != "per_class_zlib_b64"}
    chunks = [table[index:index + 3900] for index in range(0, len(table), 3900)] or [""]
    header["per_class_chunks"] = len(chunks)
    annotate("notice", "PHPUnit digest", junit.dumps(header))
    for number, chunk in enumerate(chunks, start=1):
        annotate("notice", f"PHPUnit per-class {number}/{len(chunks)}", chunk)
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="lycenza-ci", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)

    c = sub.add_parser("classify")
    c.add_argument("--event", default="push")
    c.add_argument("--ref", default="")
    c.add_argument("--before", default="")
    c.add_argument("--pr-base", default="")
    c.add_argument("--main-ref", default="origin/main")
    c.set_defaults(func=cmd_classify)

    g = sub.add_parser("gate")
    g.set_defaults(func=cmd_gate)

    p = sub.add_parser("publication-check")
    p.add_argument("--candidate", default="HEAD")
    p.add_argument("--remote", default="origin")
    p.add_argument("--repo", default="")
    p.add_argument("--max-age-hours", type=float, default=publication.DEFAULT_MAX_AGE_HOURS)
    p.add_argument("--push", action="store_true", help="fast-forward remote main to the candidate when publishable")
    p.set_defaults(func=cmd_publication_check)

    d = sub.add_parser("drift")
    d.add_argument("--remote", default="origin")
    d.add_argument("--repo", default="")
    d.add_argument("--state", choices=drift_mod.STATES)
    d.set_defaults(func=cmd_drift)

    j = sub.add_parser("junit-digest")
    j.add_argument("report", nargs="?", default="")
    j.add_argument("--out", default="")
    j.add_argument("--compare", nargs=2, metavar=("LEFT_JSON", "RIGHT_JSON"))
    j.set_defaults(func=cmd_junit_digest)

    args = parser.parse_args(argv)
    return int(args.func(args))
