"""verify-artifact: the ONE aggregate release verification (ADR 0052 sections 3.11, 3.13).

Reads only the artifact policy, the exception file and an evidence
directory; returns one PASS/FAIL (VERIFIED/FAIL) with a per-check table of
bounded codes. It never pushes, promotes or edits anything, and never
records PUBLISHED or PROMOTED. Missing, malformed, unsigned, mismatched,
expired or failing -> FAIL; there is no warn-and-continue.
"""

from __future__ import annotations

import argparse
import json
import sys
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any

from . import evidence as ev
from . import findings as fnd
from .evaluate import evaluate
from .exceptions import load_exceptions
from .lineage import on_protected_branch
from .oci import read_archive
from .policy import load_policy
from .provenance import dockerfile_at, verify_statement
from .sbom import verify_sbom
from .signing import verifier_for
from .util import (
    DIGEST,
    REPO_ROOT,
    ReleaseError,
    iso,
    load_json,
    parse_time,
    run,
    sha256_file,
    utc_now,
    write_json,
)


def tool_version(pinned: str) -> str:
    return pinned.split("@", 1)[0].rsplit(":", 1)[1].removeprefix("v")


@dataclass
class Check:
    name: str
    ok: bool
    code: str


@dataclass
class Context:
    image: str
    digest: str
    evidence: Path
    repo: Path
    now: datetime
    archive: Path | None = None
    test_public_key: Path | None = None
    rescan_report: Path | None = None
    policy: dict[str, Any] = field(default_factory=dict)
    exceptions: list[Any] = field(default_factory=list)
    bundle: dict[str, Any] = field(default_factory=dict)
    vulnerability: dict[str, Any] = field(default_factory=dict)

    def path(self, rel: str) -> Path:
        return self.evidence / rel

    def image_path(self, name: str) -> Path:
        return self.evidence / "images" / self.image / name


def _policy(ctx: Context) -> str:
    ctx.policy = load_policy()
    if ctx.image not in ctx.policy["images"]:
        raise ReleaseError("unknown_image")
    return "policy_valid"


def _exceptions(ctx: Context) -> str:
    ctx.exceptions = load_exceptions(ctx.policy, ctx.now.date())
    return "exceptions_valid"


def _complete(ctx: Context) -> str:
    missing = [r for r in (*ev.REQUIRED_COMMON, ev.BUNDLE, ev.SIGNATURE) if not ctx.path(r).is_file()]
    missing += [n for n in ev.REQUIRED_PER_IMAGE if not ctx.image_path(n).is_file()]
    if missing:
        raise ReleaseError("evidence_missing", ", ".join(missing[:5]))
    if ev.forbidden_files(ctx.evidence):
        raise ReleaseError("evidence_contains_forbidden_file")
    return "evidence_complete"


def _signature(ctx: Context) -> str:
    verifier = verifier_for(ctx.policy, ctx.test_public_key)
    if not verifier.verify(ctx.path(ev.BUNDLE), ctx.path(ev.SIGNATURE)):
        raise ReleaseError("signature_invalid")
    return "signature_valid_non_production_test_key" if verifier.non_production else "signature_valid"


def _integrity(ctx: Context) -> str:
    ctx.bundle = load_json(ctx.path(ev.BUNDLE))
    listed = ctx.bundle.get("files")
    if not isinstance(listed, dict) or sorted(listed) != sorted(ev.evidence_files(ctx.evidence)):
        raise ReleaseError("bundle_file_set_mismatch")
    for rel, digest in listed.items():
        if sha256_file(ctx.path(rel)) != digest:
            raise ReleaseError("bundle_file_modified")
    if ctx.bundle.get("images", {}).get(ctx.image) != ev.image_summary(ctx.evidence, ctx.image):
        raise ReleaseError("bundle_image_summary_mismatch")
    return "bundle_integrity_valid"


def _digest(ctx: Context) -> str:
    build = load_json(ctx.image_path("build.json"))
    if not DIGEST.match(ctx.digest) or ctx.digest != ctx.bundle["images"][ctx.image]["digest"] or ctx.digest != build["manifest_digest"]:
        raise ReleaseError("digest_mismatch")
    if ctx.archive is not None:
        artifact = read_archive(ctx.archive)
        if artifact.manifest_digest != ctx.digest or artifact.config_digest != build["config_digest"]:
            raise ReleaseError("archive_digest_mismatch")
        if artifact.platform != ctx.policy["images"][ctx.image]["platform"]:
            raise ReleaseError("archive_platform_mismatch")
        return "digest_matches_archive"
    return "digest_matches_evidence"


def _states(ctx: Context) -> str:
    for state in (ctx.evidence / "state").glob("*.json") if (ctx.evidence / "state").is_dir() else []:
        if load_json(state).get("state") in ev.DEPLOY_GATED:
            raise ReleaseError("state_not_permitted")
    return "no_deploy_gated_state_claimed"


def _lineage(ctx: Context) -> str:
    run = load_json(ctx.path("run.json"))
    if run.get("commit") != ctx.bundle.get("commit") or run.get("run_id") != ctx.bundle.get("run_id"):
        raise ReleaseError("run_record_mismatch")
    if not on_protected_branch(ctx.repo, ctx.bundle["commit"], ctx.policy["source"]["protected_remote_ref"]):
        raise ReleaseError("source_not_on_protected_main")
    return "source_on_protected_main"


def _linkage(ctx: Context) -> str:
    commit, run_id = ctx.bundle["commit"], ctx.bundle["run_id"]
    tests = load_json(ctx.path("tests/complete-regression.json"))
    build = load_json(ctx.image_path("build.json"))
    if tests.get("suite") != "complete-regression" or tests.get("result") != "passed":
        raise ReleaseError("complete_regression_not_passed")
    if tests.get("commit") != commit or tests.get("run_id") != run_id:
        raise ReleaseError("test_linkage_mismatch")
    if build.get("commit") != commit or build.get("run_id") != run_id:
        raise ReleaseError("build_linkage_mismatch")
    if build.get("builder_image") != ctx.policy["tools"]["buildkit"]:
        raise ReleaseError("builder_not_pinned")
    return "tests_build_evidence_same_run_and_commit"


def _provenance(ctx: Context) -> str:
    image = ctx.policy["images"][ctx.image]
    try:
        dockerfile = dockerfile_at(ctx.repo, ctx.bundle["commit"], image["dockerfile"])
    except ReleaseError:
        dockerfile = None
    errors = verify_statement(
        load_json(ctx.image_path("provenance.intoto.json")), policy=ctx.policy, image_key=ctx.image,
        manifest_digest=ctx.digest, commit=ctx.bundle["commit"], run_id=ctx.bundle["run_id"],
        byproduct_digests={"sbom.spdx.json": sha256_file(ctx.image_path("sbom.spdx.json")),
                           "grype.json": sha256_file(ctx.image_path("grype.json"))},
        dockerfile_text=dockerfile,
    )
    if dockerfile is None:
        errors.append("provenance_source_unavailable")
    if errors:
        raise ReleaseError(errors[0])
    tests = load_json(ctx.path("tests/complete-regression.json"))
    statement = load_json(ctx.image_path("provenance.intoto.json"))
    annotations = next((b.get("annotations") for b in statement["predicate"]["runDetails"]["byproducts"] if b.get("name") == "complete-regression"), None)
    if not annotations or annotations.get("test_run_id") != tests.get("run_id"):
        raise ReleaseError("provenance_test_run_mismatch")
    return "provenance_valid"


def _sbom(ctx: Context) -> str:
    errors = verify_sbom(load_json(ctx.image_path("sbom.spdx.json")), ctx.digest,
                         ctx.policy["images"][ctx.image]["sbom_required_purl_types"], tool_version(ctx.policy["tools"]["syft"]))
    if errors:
        raise ReleaseError(errors[0])
    return "sbom_valid_for_digest"


def _scanner(ctx: Context) -> str:
    scanner = load_json(ctx.image_path("scanner.json"))
    report = load_json(ctx.image_path("grype.json"))
    if scanner.get("image") != ctx.policy["tools"]["grype"]:
        raise ReleaseError("scanner_not_pinned")
    descriptor = report.get("descriptor", {})
    if descriptor.get("name") != "grype" or descriptor.get("version") != tool_version(ctx.policy["tools"]["grype"]):
        raise ReleaseError("scanner_version_mismatch")
    try:
        if descriptor["db"]["status"]["valid"] is not True:
            raise ReleaseError("scanner_db_invalid")
        built = parse_time(str(descriptor["db"]["status"]["built"]))
        scanned = parse_time(str(descriptor["timestamp"]))
        recorded = parse_time(str(scanner["db_built"]))
    except (KeyError, TypeError, ValueError) as exc:
        raise ReleaseError("scanner_metadata_missing") from exc
    if built != recorded:
        raise ReleaseError("scanner_metadata_mismatch")
    age = scanned - built
    if age > timedelta(hours=ctx.policy["vulnerability_policy"]["scanner_db_max_age_hours"]) or age < timedelta(0):
        raise ReleaseError("scanner_db_stale")
    if report.get("source", {}).get("target", {}).get("manifestDigest") not in (None, ctx.digest):
        raise ReleaseError("scan_subject_mismatch")
    return "scanner_pinned_db_fresh"


def _at_commit(ctx: Context, path: str) -> Any:
    try:
        return json.loads(run(["git", "-C", str(ctx.repo), "show", f"{ctx.bundle['commit']}:{path}"], code="source_unavailable").stdout)
    except json.JSONDecodeError as exc:
        raise ReleaseError("source_unavailable") from exc


def _collect_findings(ctx: Context, grype_report: Any) -> list[fnd.Finding]:
    """This image's findings: the Grype report plus the language audits of what the image installs
    (Composer and npm for the application, pip for the Gateway), versions read from the locks AT the commit."""
    unrated = ctx.policy["vulnerability_policy"]["unrated_language_advisory_severity"]
    found = fnd.grype(grype_report, ctx.image)
    if ctx.image == "app":
        found += fnd.composer_audit(load_json(ctx.path("audits/composer-audit.json")),
                                    fnd.composer_locked_versions(_at_commit(ctx, "apps/platform/composer.lock")), unrated)
        found += fnd.npm_audit(load_json(ctx.path("audits/npm-audit.json")),
                               fnd.npm_locked_versions(_at_commit(ctx, "apps/platform/package-lock.json")))
    else:
        found += fnd.pip_audit(load_json(ctx.path("audits/pip-audit.json")), unrated)
    return found


def _vulnerabilities(ctx: Context) -> str:
    ctx.vulnerability = evaluate(_collect_findings(ctx, load_json(ctx.image_path("grype.json"))), ctx.exceptions)
    if ctx.vulnerability["verdict"] != "PASS":
        raise ReleaseError("vulnerability_policy_blocking_findings")
    return "vulnerability_policy_passed"


def _rescan(ctx: Context) -> str:
    if ctx.rescan_report is None:
        return "not_requested"
    result = evaluate(fnd.grype(load_json(ctx.rescan_report), ctx.image), ctx.exceptions)
    ctx.vulnerability["rescan"] = result
    if result["verdict"] != "PASS":
        # A newly found Critical in an old digest needs operator/security judgement
        # (an exception record) -- never an automatic pass or an automatic rollback.
        raise ReleaseError("rescan_blocking_findings_require_judgement")
    return "rescan_passed"


def _secrets(ctx: Context) -> str:
    if load_json(ctx.path("source/gitleaks.json")) != []:
        raise ReleaseError("source_secret_scan_findings")
    if load_json(ctx.image_path("gitleaks.json")) != []:
        raise ReleaseError("image_secret_scan_findings")
    scan = load_json(ctx.image_path("image-scan.json"))
    if scan.get("config_issues") != [] or scan.get("rootfs_issues") != []:
        raise ReleaseError("image_config_or_filesystem_findings")
    return "no_secret_findings"


CHECKS: list[tuple[str, Callable[[Context], str]]] = [
    ("policy", _policy),
    ("exceptions", _exceptions),
    ("evidence_complete", _complete),
    ("signature", _signature),
    ("bundle_integrity", _integrity),
    ("artifact_digest", _digest),
    ("artifact_state", _states),
    ("source_lineage", _lineage),
    ("test_build_linkage", _linkage),
    ("provenance", _provenance),
    ("sbom", _sbom),
    ("scanner_freshness", _scanner),
    ("vulnerability_policy", _vulnerabilities),
    ("rescan", _rescan),
    ("secret_and_history_scans", _secrets),
]
# Checks after these depend on what they establish; a failure here stops the run.
_GATING = {"policy", "exceptions", "evidence_complete", "signature", "bundle_integrity"}


def verify(ctx: Context) -> tuple[str, list[Check]]:
    results: list[Check] = []
    for name, check in CHECKS:
        try:
            results.append(Check(name, True, check(ctx)))
        except ReleaseError as exc:
            results.append(Check(name, False, exc.code))
        except (KeyError, TypeError, ValueError, AttributeError, IndexError):
            results.append(Check(name, False, "evidence_malformed"))
        if not results[-1].ok and name in _GATING:
            results += [Check(n, False, "not_evaluated") for n, _ in CHECKS[len(results):]]
            break
    verdict = ev.VERIFIED if all(c.ok for c in results) else ev.FAILED
    return verdict, results


def render(ctx: Context, verdict: str, results: list[Check]) -> str:
    lines = [f"verify-artifact  image={ctx.image}  digest={ctx.digest}", ""]
    lines += [f"  {'PASS' if c.ok else 'FAIL'}  {c.name:<26} {c.code}" for c in results]
    if ctx.vulnerability:
        counts = ctx.vulnerability.get("counts", {})
        lines += ["", "  findings by severity: " + ", ".join(f"{k}={v}" for k, v in counts.items()),
                  f"  blocking: {len(ctx.vulnerability.get('blocking', []))}  excepted: {len(ctx.vulnerability.get('excepted', []))}"]
    lines += ["", f"RESULT: {'PASS' if verdict == ev.VERIFIED else 'FAIL'}  state={verdict}"
              + ("  (NON-PRODUCTION test signing key)" if ctx.test_public_key else "")]
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="verify-artifact", description="Aggregate release artifact verification (ADR 0052). Never pushes or promotes.")
    parser.add_argument("--image", required=True, choices=["app", "ai-gateway"])
    parser.add_argument("--digest", required=True, help="the manifest digest to verify (sha256:...)")
    parser.add_argument("--evidence", required=True, type=Path)
    parser.add_argument("--archive", type=Path, help="the OCI archive, to prove the digest against the artifact itself")
    parser.add_argument("--repo", type=Path, default=REPO_ROOT)
    parser.add_argument("--test-public-key", type=Path, help="NON-PRODUCTION: the ephemeral per-run public key")
    parser.add_argument("--rescan-report", type=Path, help="a fresh Grype report of the same digest (rollback re-verification)")
    parser.add_argument("--record", type=Path, help="write the verification record (JSON) here")
    parser.add_argument("--record-state", action="store_true", help="record BUILT->VERIFIED/FAIL in <evidence>/state/<image>.json")
    parser.add_argument("--now", help=argparse.SUPPRESS)
    args = parser.parse_args(argv)

    now = parse_time(args.now) if args.now else utc_now()
    ctx = Context(image=args.image, digest=args.digest, evidence=args.evidence.resolve(), repo=args.repo.resolve(), now=now,
                  archive=args.archive, test_public_key=args.test_public_key, rescan_report=args.rescan_report)
    verdict, results = verify(ctx)
    print(render(ctx, verdict, results))

    record = {"image": ctx.image, "digest": ctx.digest, "verdict": "PASS" if verdict == ev.VERIFIED else "FAIL", "state": verdict,
              "verified_at": iso(now), "signing": "ephemeral-test (NON-PRODUCTION)" if ctx.test_public_key else ctx.policy.get("signing", {}).get("custody", "unknown"),
              "checks": [{"check": c.name, "result": "PASS" if c.ok else "FAIL", "code": c.code} for c in results],
              "vulnerabilities": ctx.vulnerability}
    if args.record:
        write_json(args.record, record)
    if args.record_state and ctx.evidence.is_dir() and DIGEST.match(ctx.digest):
        ev.write_state(ctx.evidence, ctx.image, verdict, ctx.digest, {"verdict": record["verdict"],
                       "failed_checks": [c.code for c in results if not c.ok]})
    return 0 if verdict == ev.VERIFIED else 1


if __name__ == "__main__":
    sys.exit(main())
