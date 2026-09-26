"""qualify: the release-qualification entry point (ADR 0052 sections 3.15, 3.19).

Every stage writes into ONE evidence directory whose run.json fixes the
commit and run id; every stage refuses a different HEAD or a dirty tree, so
tests, build and evidence come from one run on one commit. Security
decisions are not made here: vulnerability thresholds live in evaluate.py,
the final verdict comes only from verify-artifact (verify.py). This entry
point never pushes, publishes or promotes, and uses only an ephemeral
NON-PRODUCTION signing key.
"""

from __future__ import annotations

import argparse
import os
import re
import secrets
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
from pathlib import Path
from typing import Any

from . import evidence as ev
from . import findings as fnd
from .evaluate import evaluate
from .exceptions import load_exceptions
from .imagescan import apply_allowlist, scan_config, scan_rootfs
from .lineage import on_protected_branch
from .oci import read_archive
from .policy import IMAGE_KEYS, load_policy
from .provenance import build_statement
from .signing import EphemeralTestSigner
from .tools import docker_run
from .util import (
    COMMIT,
    RELEASE_DIR,
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
from .verify import main as verify_main

TOOLS_LOCK = RELEASE_DIR / "requirements-tools.lock"
PIP_COMPILE = ["pip-compile", "--quiet", "--generate-hashes", "--strip-extras", "--no-emit-index-url",
               "--output-file=requirements.lock", "requirements.txt"]


def log(message: str) -> None:
    print(f"[qualify] {message}", flush=True)


# --- run identity -----------------------------------------------------------

def head_commit(repo: Path) -> str:
    commit = run(["git", "-C", str(repo), "rev-parse", "HEAD"], code="source_unavailable").stdout.decode().strip()
    if not COMMIT.match(commit):
        raise ReleaseError("source_unavailable")
    return commit


def require_clean(repo: Path) -> None:
    status = run(["git", "-C", str(repo), "status", "--porcelain", "--untracked-files=all"], code="source_unavailable").stdout
    if status.strip():
        raise ReleaseError("source_tree_dirty")


def run_identity() -> tuple[str, str]:
    """(run id, builder id) -- from the CI run when present, else a clearly local identity."""
    if os.environ.get("GITHUB_ACTIONS") == "true":
        run_id = f"{os.environ['GITHUB_RUN_ID']}-{os.environ.get('GITHUB_RUN_ATTEMPT', '1')}"
        builder = f"{os.environ['GITHUB_SERVER_URL']}/{os.environ['GITHUB_WORKFLOW_REF']}"
        if not re.fullmatch(r"[0-9]+-[0-9]+", run_id) or not re.fullmatch(r"https://[A-Za-z0-9./@_-]+", builder):
            raise ReleaseError("run_identity_invalid")
        return run_id, builder
    return f"local-{utc_now().strftime('%Y%m%dT%H%M%SZ')}-{secrets.token_hex(4)}", "local-operator:infrastructure/release/qualify"


def load_run(out: Path, repo: Path) -> dict[str, Any]:
    record = load_json(out / "run.json")
    if head_commit(repo) != record["commit"]:
        raise ReleaseError("head_changed_during_run")
    require_clean(repo)
    return record


def cmd_init(args: argparse.Namespace) -> None:
    policy = load_policy()
    repo = args.repo
    require_clean(repo)
    commit = head_commit(repo)
    run_id, builder = run_identity()
    args.out.mkdir(parents=True, exist_ok=True)
    if any(args.out.iterdir()):
        raise ReleaseError("evidence_directory_not_empty")
    protected = policy["source"]["protected_remote_ref"]
    write_json(args.out / "run.json", {
        "commit": commit, "run_id": run_id, "builder_id": builder, "started": iso(utc_now()),
        "source_repository": policy["source"]["repository"], "protected_ref": protected,
        "on_protected_main_at_start": on_protected_branch(repo, commit, protected),
    })
    log(f"run {run_id} for commit {commit}")


# --- lockfile integrity --------------------------------------------------------

def _copy(files: list[Path], into: Path) -> None:
    for file in files:
        shutil.copyfile(file, into / file.name)


def tools_venv_command(command: str) -> list[str]:
    """Install the hash-locked release tools (wheels only) in a throwaway venv, then run `command`."""
    return ["sh", "-c", "python -m venv /tmp/tools && /tmp/tools/bin/pip install --quiet --disable-pip-version-check "
            "--no-cache-dir --require-hashes --no-deps --only-binary=:all: -r /release/requirements-tools.lock "
            f"&& PATH=/tmp/tools/bin:$PATH && {command}"]


def cmd_inputs(args: argparse.Namespace) -> None:
    policy = load_policy()
    load_run(args.out, args.repo)
    platform, ai = args.repo / "apps/platform", args.repo / "services/ai"
    results: dict[str, str] = {}

    docker_run(policy["tools"]["composer"], ["validate", "--strict", "--no-check-publish", "--no-interaction"],
               mounts=[(platform / "composer.json", "/app/composer.json", "ro"), (platform / "composer.lock", "/app/composer.lock", "ro")],
               workdir="/app", network="none", env={"COMPOSER_HOME": "/tmp/composer"}, code="composer_lock_out_of_sync")
    results["composer"] = "composer.lock valid and in sync with composer.json"

    # `npm ci` refuses a package-lock.json that is out of sync with package.json;
    # --dry-run makes that exact check without installing anything.
    docker_run(policy["tools"]["node"], ["npm", "ci", "--dry-run", "--ignore-scripts", "--no-audit", "--no-fund"],
               mounts=[(platform / name, f"/w/platform/{name}", "ro") for name in ("package.json", "package-lock.json", ".npmrc")],
               workdir="/w/platform", env={"npm_config_cache": "/tmp/npm-cache"}, network="none", code="npm_lock_out_of_sync")
    results["npm"] = "package-lock.json in sync with package.json (npm ci --dry-run)"

    with tempfile.TemporaryDirectory(prefix="lycenza-pip-lock-") as tmp:
        work = Path(tmp)
        _copy([ai / "requirements.txt", ai / "requirements.lock"], work)
        docker_run(policy["tools"]["python"], tools_venv_command(" ".join(PIP_COMPILE)),
                   mounts=[(work, "/w", "rw"), (RELEASE_DIR, "/release", "ro")], workdir="/w",
                   env={"PIP_DEFAULT_TIMEOUT": "120", "HOME": "/tmp"}, code="python_lock_check_failed")
        if sha256_file(work / "requirements.lock") != sha256_file(ai / "requirements.lock"):
            raise ReleaseError("python_lock_out_of_sync")
    results["pip"] = "services/ai/requirements.lock is exactly what pip-compile produces from requirements.txt"
    write_json(args.out / "inputs.json", results)
    log("lockfile integrity: composer, npm and Python locks are in sync")


# --- same-run regression -------------------------------------------------------

def cmd_regression(args: argparse.Namespace) -> None:
    record = load_run(args.out, args.repo)
    if not args.command:
        raise ReleaseError("regression_command_missing")
    started = utc_now()
    log("running the complete regression: " + " ".join(args.command))
    result = subprocess.run(args.command, cwd=args.repo, check=False)
    if head_commit(args.repo) != record["commit"]:
        raise ReleaseError("head_changed_during_run")
    write_json(args.out / "tests" / "complete-regression.json", {
        "suite": "complete-regression", "commit": record["commit"], "run_id": record["run_id"],
        "command": " ".join(args.command), "result": "passed" if result.returncode == 0 else "failed",
        "exit_code": result.returncode, "started": iso(started), "finished": iso(utc_now()),
    })
    if result.returncode != 0:
        raise ReleaseError("complete_regression_failed")
    log("complete regression passed")


# --- source secret scan and language audits --------------------------------------

def export_commit(repo: Path, commit: str, into: Path, paths: list[str] | None = None) -> None:
    archive = run(["git", "-C", str(repo), "archive", "--format=tar", commit, *(paths or [])], code="source_unavailable").stdout
    with tarfile.open(fileobj=__import__("io").BytesIO(archive)) as tar:
        tar.extractall(into, filter="data")


def cmd_source_scan(args: argparse.Namespace) -> None:
    policy = load_policy()
    record = load_run(args.out, args.repo)
    (args.out / "source").mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="lycenza-source-") as tmp:
        export_commit(args.repo, record["commit"], Path(tmp))
        docker_run(policy["tools"]["gitleaks"], ["dir", "/repo", "--config", "/cfg/.gitleaks.toml", "--no-banner", "--redact",
                                                 "--exit-code", "0", "--report-format", "json", "--report-path", "/out/gitleaks.json"],
                   mounts=[(Path(tmp), "/repo", "ro"), (args.repo / ".gitleaks.toml", "/cfg/.gitleaks.toml", "ro"),
                           (args.out / "source", "/out", "rw")], network="none", code="source_secret_scan_failed")
    count = len(load_json(args.out / "source" / "gitleaks.json"))
    log(f"source secret scan: {count} finding(s)")
    if count:
        raise ReleaseError("source_secret_scan_findings")


def cmd_audit(args: argparse.Namespace) -> None:
    policy = load_policy()
    record = load_run(args.out, args.repo)
    out = args.out / "audits"
    out.mkdir(parents=True, exist_ok=True)
    platform, ai = args.repo / "apps/platform", args.repo / "services/ai"

    composer = docker_run(policy["tools"]["composer"], ["audit", "--locked", "--no-dev", "--format=json", "--no-interaction", "--abandoned=report"],
                          mounts=[(platform / "composer.json", "/app/composer.json", "ro"), (platform / "composer.lock", "/app/composer.lock", "ro")],
                          workdir="/app", env={"COMPOSER_HOME": "/tmp/composer"}, check=False, code="composer_audit_failed")
    # All npm packages are audited: they are build-time inputs of the bundled assets.
    npm = docker_run(policy["tools"]["node"], ["npm", "audit", "--json", "--package-lock-only"],
                     mounts=[(platform / "package.json", "/w/package.json", "ro"), (platform / "package-lock.json", "/w/package-lock.json", "ro")],
                     workdir="/w", env={"npm_config_cache": "/tmp/npm-cache"}, check=False, code="npm_audit_failed")
    pip = docker_run(policy["tools"]["python"], tools_venv_command(
        "pip-audit -r /ai/requirements.lock --require-hashes --disable-pip --format json --progress-spinner off"),
        mounts=[(ai / "requirements.lock", "/ai/requirements.lock", "ro"), (RELEASE_DIR, "/release", "ro")],
        env={"PIP_DEFAULT_TIMEOUT": "120", "HOME": "/tmp"}, check=False, code="pip_audit_failed")

    for name, result in (("composer", composer), ("npm", npm), ("pip", pip)):
        # Reports are stored exactly as the tool printed them; a non-JSON report is a failed audit.
        (out / f"{name}-audit.json").write_bytes(result.stdout)
        load_json(out / f"{name}-audit.json")

    unrated = policy["vulnerability_policy"]["unrated_language_advisory_severity"]
    found = (fnd.composer_audit(load_json(out / "composer-audit.json"), fnd.composer_locked_versions(load_json(platform / "composer.lock")), unrated)
             + fnd.npm_audit(load_json(out / "npm-audit.json"), fnd.npm_locked_versions(load_json(platform / "package-lock.json")))
             + fnd.pip_audit(load_json(out / "pip-audit.json"), unrated))
    result = evaluate(found, load_exceptions(policy, utc_now().date()))
    log(f"language audits: {len(found)} advisory finding(s); blocking {len(result['blocking'])} (reported only; no dependency was changed)")
    for item in result["blocking"]:
        log(f"  blocking: {item['image']} {item['package']} {item['version']} {item['id']} ({item['reason']})")
    _ = record
    if getattr(args, "fail_on_blocking", False) and result["blocking"]:
        raise ReleaseError("language_audit_blocking_findings")


# --- build, image checks, SBOM, scan -------------------------------------------------

def _archive_paths(artifacts: Path, key: str) -> tuple[Path, Path]:
    return artifacts / f"{key}.oci.tar", artifacts / f"{key}.docker.tar"


def local_tag(policy: dict[str, Any], key: str, commit: str) -> str:
    return f"{policy['images'][key]['name']}:qualify-{commit[:12]}"


def _base_labels(config: dict[str, Any]) -> dict[str, str]:
    """Labels inherited from a base image (none of ours start with org.opencontainers.image.)."""
    return {k: v for k, v in ((config.get("config") or {}).get("Labels") or {}).items() if not k.startswith("org.opencontainers.image.")}


def cmd_build(args: argparse.Namespace) -> None:
    policy = load_policy()
    record = load_run(args.out, args.repo)
    args.artifacts.mkdir(parents=True, exist_ok=True)
    builder = f"lycenza-release-{secrets.token_hex(4)}"
    run(["docker", "buildx", "create", "--name", builder, "--driver", "docker-container",
         "--driver-opt", f"image={policy['tools']['buildkit']}"], code="builder_unavailable")
    try:
        for key in args.images:
            image = policy["images"][key]
            oci, docker_tar = _archive_paths(args.artifacts, key)
            created = utc_now()
            with tempfile.TemporaryDirectory(prefix="lycenza-context-") as tmp:
                # The build context is the COMMIT (git archive), never the working tree.
                export_commit(args.repo, record["commit"], Path(tmp), [image["context"], image["dockerfile"]])
                version = f"{created.strftime('%Y%m%d')}-{record['commit'][:12]}"
                labels = {
                    "org.opencontainers.image.source": policy["source"]["repository"],
                    "org.opencontainers.image.revision": record["commit"],
                    "org.opencontainers.image.version": version,
                    "org.opencontainers.image.created": iso(created),
                }
                command = ["docker", "buildx", "build", "--builder", builder, "--no-cache", "--pull",
                           "--provenance=false", "--sbom=false", "--platform", image["platform"], "--target", image["target"],
                           "-f", str(Path(tmp) / image["dockerfile"])]
                for name, value in labels.items():
                    command += ["--label", f"{name}={value}"]
                command += ["--output", f"type=oci,dest={oci},name={local_tag(policy, key, record['commit'])}",
                            "--output", f"type=docker,dest={docker_tar},name={local_tag(policy, key, record['commit'])}",
                            str(Path(tmp) / image["context"])]
                log(f"building {key} from {record['commit'][:12]} (fresh builder, no cache)")
                started = time.monotonic()
                built = run(command[:3] + ["--progress=plain"] + command[3:], code="build_failed", timeout=5400, check=False)
                (args.artifacts / f"{key}.build.log").write_bytes(built.stderr)
                if built.returncode != 0:
                    raise ReleaseError("build_failed", f"see {key}.build.log")
                seconds = round(time.monotonic() - started)
            artifact = read_archive(oci)
            if artifact.platform != image["platform"]:
                raise ReleaseError("archive_platform_mismatch")
            if (artifact.config.get("config") or {}).get("Labels", {}) != {**_base_labels(artifact.config), **labels}:
                raise ReleaseError("oci_labels_missing")
            loaded = run(["docker", "load", "-i", str(docker_tar)], code="image_load_failed").stdout.decode()
            image_id = run(["docker", "image", "inspect", "--format", "{{.Id}}", local_tag(policy, key, record["commit"])], code="image_load_failed").stdout.decode().strip()
            if image_id != artifact.config_digest:
                raise ReleaseError("loaded_image_mismatch")
            (args.out / "images" / key).mkdir(parents=True, exist_ok=True)
            write_json(args.out / "images" / key / "build.json", {
                "name": image["name"], "manifest_digest": artifact.manifest_digest, "config_digest": artifact.config_digest,
                "platform": artifact.platform, "commit": record["commit"], "run_id": record["run_id"],
                "dockerfile": image["dockerfile"], "context": image["context"], "target": image["target"],
                "builder_image": policy["tools"]["buildkit"], "labels": labels, "archive_sha256": sha256_file(oci),
                "started": iso(created), "finished": iso(utc_now()), "build_seconds": seconds,
            })
            ev.write_state(args.out, key, ev.BUILT, artifact.manifest_digest)
            log(f"{key}: BUILT {artifact.manifest_digest} ({seconds}s); {loaded.strip()}")
    finally:
        run(["docker", "buildx", "rm", "--force", builder], check=False)


def cmd_verify_images(args: argparse.Namespace) -> None:
    policy = load_policy()
    record = load_run(args.out, args.repo)
    tags = {key: local_tag(policy, key, record["commit"]) for key in IMAGE_KEYS}
    env = dict(os.environ, APP_IMAGE=tags["app"], AI_IMAGE=tags["ai-gateway"])
    result = subprocess.run([str(args.repo / "infrastructure/docker/production/verify-images.sh")], env=env, check=False,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    summary = [line for line in result.stdout.decode(errors="replace").splitlines() if line.startswith(("PASS", "FAIL", "verify-images:"))]
    # The config digest of each image actually verified, binding this record (and the runtime-hardening proof
    # it carries for conditional exceptions) to the built artifact.
    images = {key: run(["docker", "image", "inspect", "--format", "{{.Id}}", tag], code="image_inspect_failed").stdout.decode().strip()
              for key, tag in tags.items()}
    write_json(args.out / "verify-images.json", {"exit_code": result.returncode, "images": images, "checks": summary})
    log(summary[-1] if summary else "verify-images produced no summary")
    if result.returncode != 0:
        raise ReleaseError("production_image_verification_failed")


def _export_rootfs(tag: str, into: Path) -> None:
    """The image filesystem as a non-root, permission-normalized tree (device nodes skipped)."""
    container = run(["docker", "create", tag], code="image_export_failed").stdout.decode().strip()
    try:
        exported = subprocess.Popen(["docker", "export", container], stdout=subprocess.PIPE)
        untar = subprocess.run(["tar", "-x", "-C", str(into), "--no-same-owner", "--no-same-permissions",
                                "--exclude=dev/*", "--exclude=proc/*", "--exclude=sys/*"], stdin=exported.stdout,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        if exported.wait() != 0 or untar.returncode != 0:
            raise ReleaseError("image_export_failed")
    finally:
        run(["docker", "rm", "-f", container], check=False)


def cmd_scan(args: argparse.Namespace) -> None:
    policy = load_policy()
    record = load_run(args.out, args.repo)
    grype_cache = Path(tempfile.mkdtemp(prefix="lycenza-grype-db-"))
    try:
        log("updating the Grype vulnerability database (fresh, per run)")
        docker_run(policy["tools"]["grype"], ["db", "update"], mounts=[(grype_cache, "/cache", "rw")],
                   env={"GRYPE_DB_CACHE_DIR": "/cache", "GRYPE_CHECK_FOR_APP_UPDATE": "false"}, code="scanner_db_update_failed")
        for key in args.images:
            base = args.out / "images" / key
            build = load_json(base / "build.json")
            oci, _ = _archive_paths(args.artifacts, key)
            if read_archive(oci).manifest_digest != build["manifest_digest"]:
                raise ReleaseError("archive_digest_mismatch")

            docker_run(policy["tools"]["syft"], [f"oci-archive:/artifacts/{oci.name}", "-o", "spdx-json=/out/sbom.spdx.json", "-q"],
                       mounts=[(args.artifacts.resolve(), "/artifacts", "ro"), (base.resolve(), "/out", "rw")],
                       env={"SYFT_CHECK_FOR_APP_UPDATE": "false"}, network="none", code="sbom_generation_failed")
            docker_run(policy["tools"]["grype"], ["sbom:/out/sbom.spdx.json", "-o", "json", "--file", "/out/grype.json", "-q"],
                       mounts=[(base.resolve(), "/out", "rw"), (grype_cache, "/cache", "rw")],
                       env={"GRYPE_DB_CACHE_DIR": "/cache", "GRYPE_DB_AUTO_UPDATE": "false", "GRYPE_CHECK_FOR_APP_UPDATE": "false"},
                       network="none", code="vulnerability_scan_failed")
            report = load_json(base / "grype.json")
            status = report["descriptor"]["db"]["status"]
            write_json(base / "scanner.json", {
                "image": policy["tools"]["grype"], "version": report["descriptor"]["version"],
                "db_schema": status.get("schemaVersion"), "db_built": status.get("built"),
                "scanned_at": report["descriptor"]["timestamp"], "input": "sbom.spdx.json",
                "db_age_hours": round((parse_time(report["descriptor"]["timestamp"]) - parse_time(status["built"])).total_seconds() / 3600, 2),
            })

            with tempfile.TemporaryDirectory(prefix="lycenza-rootfs-") as tmp:
                rootfs = Path(tmp)
                _export_rootfs(local_tag(policy, key, record["commit"]), rootfs)
                docker_run(policy["tools"]["gitleaks"], ["dir", "/image", "--config", "/cfg/gitleaks-image.toml", "--no-banner", "--redact",
                                                         "--exit-code", "0", "--report-format", "json", "--report-path", "/out/gitleaks.json"],
                           mounts=[(rootfs, "/image", "ro"), (RELEASE_DIR / "gitleaks-image.toml", "/cfg/gitleaks-image.toml", "ro"),
                                   (base.resolve(), "/out", "rw")], network="none", code="image_secret_scan_failed")
                allowlist = load_json(RELEASE_DIR / "image-scan-allowlist.json")["entries"]
                remaining, allowed = apply_allowlist(scan_rootfs(rootfs, key), allowlist, rootfs, key)
            config_issues = scan_config(read_archive(oci, verify_layers=False).config)
            write_json(base / "image-scan.json", {"config_issues": config_issues, "rootfs_issues": remaining, "rootfs_allowlisted": allowed,
                                                  "scanned": ["env", "labels", "entrypoint", "cmd", "history", "filesystem"]})
            log(f"{key}: SBOM + scan done; {len(report['matches'])} matches; image secret findings "
                f"{len(load_json(base / 'gitleaks.json')) + len(remaining) + len(config_issues)}")
    finally:
        shutil.rmtree(grype_cache, ignore_errors=True)


# --- provenance, bundle, signature, verification ----------------------------------------

def cmd_provenance(args: argparse.Namespace) -> None:
    policy = load_policy()
    record = load_run(args.out, args.repo)
    tests = load_json(args.out / "tests" / "complete-regression.json")
    for key in args.images:
        base = args.out / "images" / key
        build = load_json(base / "build.json")
        statement = build_statement(
            policy=policy, image_key=key, manifest_digest=build["manifest_digest"], commit=record["commit"],
            dockerfile_text=run(["git", "-C", str(args.repo), "show", f"{record['commit']}:{build['dockerfile']}"]).stdout.decode(),
            builder_id=record["builder_id"], run_id=record["run_id"], started=build["started"], finished=build["finished"],
            byproducts=[{"name": "sbom.spdx.json", "mediaType": "application/spdx+json", "digest": {"sha256": sha256_file(base / "sbom.spdx.json")}},
                        {"name": "grype.json", "mediaType": "application/json", "digest": {"sha256": sha256_file(base / "grype.json")}}],
            test_run={"test_run_id": tests["run_id"], "suite": tests["suite"], "result": tests["result"]},
        )
        write_json(base / "provenance.intoto.json", statement)
        log(f"{key}: provenance statement written (no SLSA level claimed)")


def cmd_sign(args: argparse.Namespace) -> None:
    policy = load_policy()
    record = load_run(args.out, args.repo)
    with EphemeralTestSigner(policy["tools"]["cosign"]) as signer:
        write_json(args.out / "signing.json", signer.export_public_key(args.out / "signing" / "ephemeral-test.pub"))
        ev.write_bundle(args.out, record["commit"], record["run_id"], list(args.images))
        signer.sign(args.out / ev.BUNDLE, args.out / ev.SIGNATURE)
    log("evidence bundle signed with an ephemeral NON-PRODUCTION key (private key destroyed)")


def cmd_verify(args: argparse.Namespace) -> int:
    load_run(args.out, args.repo)
    status = 0
    for key in args.images:
        build = load_json(args.out / "images" / key / "build.json")
        oci, _ = _archive_paths(args.artifacts, key)
        status |= verify_main(["--image", key, "--digest", build["manifest_digest"], "--evidence", str(args.out),
                               "--archive", str(oci), "--repo", str(args.repo),
                               "--test-public-key", str(args.out / "signing" / "ephemeral-test.pub"),
                               "--record", str(args.out / "state" / f"{key}.verification.json"), "--record-state"])
    return status


# --- scheduled re-scan ---------------------------------------------------------------------

def cmd_rescan(args: argparse.Namespace) -> int:
    """Re-scan RETAINED SBOMs with a fresh database; report only (never rebuilds or redeploys)."""
    policy = load_policy()
    exceptions = load_exceptions(policy, utc_now().date())
    args.out.mkdir(parents=True, exist_ok=True)
    cache = Path(tempfile.mkdtemp(prefix="lycenza-grype-db-"))
    status = 0
    try:
        if args.report is None:
            docker_run(policy["tools"]["grype"], ["db", "update"], mounts=[(cache, "/cache", "rw")],
                       env={"GRYPE_DB_CACHE_DIR": "/cache", "GRYPE_CHECK_FOR_APP_UPDATE": "false"}, code="scanner_db_update_failed")
        for spec in args.sbom:
            key, _, path = spec.partition("=")
            if key not in IMAGE_KEYS or not path:
                raise ReleaseError("rescan_input_invalid")
            sbom = Path(path).resolve()
            document = load_json(sbom)
            subject = next((p.get("versionInfo") for p in document.get("packages", []) if p.get("primaryPackagePurpose") == "CONTAINER"), None)
            expected = dict(e.partition("=")[::2] for e in (args.expect or [])).get(key)
            if expected is not None and subject != expected:
                raise ReleaseError("rescan_sbom_subject_mismatch")
            target = args.out / f"{key}.grype.json"
            if args.report is not None:
                shutil.copyfile(args.report, target)  # fixture/offline mode
            else:
                docker_run(policy["tools"]["grype"], [f"sbom:/in/{sbom.name}", "-o", "json", "--file", f"/out/{target.name}", "-q"],
                           mounts=[(sbom.parent, "/in", "ro"), (args.out.resolve(), "/out", "rw"), (cache, "/cache", "rw")],
                           env={"GRYPE_DB_CACHE_DIR": "/cache", "GRYPE_DB_AUTO_UPDATE": "false", "GRYPE_CHECK_FOR_APP_UPDATE": "false"},
                           network="none", code="vulnerability_scan_failed")
            report = load_json(target)
            built = parse_time(report["descriptor"]["db"]["status"]["built"])
            fresh = parse_time(report["descriptor"]["timestamp"]) - built
            if fresh.total_seconds() > policy["vulnerability_policy"]["scanner_db_max_age_hours"] * 3600:
                raise ReleaseError("scanner_db_stale")
            result = evaluate(fnd.grype(report, key), exceptions)
            write_json(args.out / f"{key}.rescan.json", {"image": key, "digest": subject, "sbom_sha256": sha256_file(sbom), "db_built": iso(built),
                                                          "verdict": result["verdict"], "counts": result["counts"], "blocking": result["blocking"]})
            print(f"rescan {key}: {result['verdict']}  blocking={len(result['blocking'])}  " +
                  ", ".join(f"{k}={v}" for k, v in result["counts"].items()))
            status |= 0 if result["verdict"] == "PASS" else 1
    finally:
        shutil.rmtree(cache, ignore_errors=True)
    return status


def cmd_retained() -> int:
    from .schema import validate

    document = load_json(RELEASE_DIR / "retained-releases.json")
    errors = validate(document, load_json(RELEASE_DIR / "schema" / "retained-releases.schema.json"))
    if errors:
        raise ReleaseError("retained_releases_invalid")
    for release in document["releases"]:
        print(release["evidence_run_id"], release["image"], release["digest"], release["role"])
    return 0


# --- everything, in order ----------------------------------------------------------------------

STAGES = ("inputs", "regression", "source-scan", "audit", "build", "verify-images", "scan", "provenance", "sign", "verify")


def cmd_all(args: argparse.Namespace) -> int:
    cmd_init(args)
    timings: dict[str, float] = {}
    failures: list[str] = []
    handlers = {"inputs": cmd_inputs, "regression": cmd_regression, "source-scan": cmd_source_scan, "audit": cmd_audit,
                "build": cmd_build, "verify-images": cmd_verify_images, "scan": cmd_scan, "provenance": cmd_provenance, "sign": cmd_sign}
    for stage in STAGES[:-1]:
        started = time.monotonic()
        try:
            handlers[stage](args)
        except ReleaseError as exc:
            failures.append(f"{stage}:{exc.code}")
            log(f"stage {stage} FAILED ({exc.code})")
            # Evidence-producing stages keep going so the bundle is complete;
            # a stage the later ones depend on stops the run.
            if stage in ("build", "scan", "provenance", "sign", "regression"):
                timings[stage] = round(time.monotonic() - started, 1)
                write_json(args.out / "state" / "timings.json", {"stages_seconds": timings, "failures": failures})
                return 1
        timings[stage] = round(time.monotonic() - started, 1)
    started = time.monotonic()
    status = cmd_verify(args)
    timings["verify"] = round(time.monotonic() - started, 1)
    write_json(args.out / "state" / "timings.json", {"stages_seconds": timings, "failures": failures})
    return 1 if failures else status


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="qualify", description="Release qualification (ADR 0052). Never pushes, publishes or promotes.")
    sub = parser.add_subparsers(dest="stage", required=True)

    def add(name: str, *, images: bool = False, artifacts: bool = False, command: bool = False) -> argparse.ArgumentParser:
        p = sub.add_parser(name)
        p.add_argument("--out", required=True, type=Path, help="the run's evidence directory")
        p.add_argument("--repo", type=Path, default=REPO_ROOT)
        if images:
            p.add_argument("--image", dest="images", action="append", choices=IMAGE_KEYS)
        if artifacts:
            p.add_argument("--artifacts", required=True, type=Path, help="where OCI archives are written (never uploaded as evidence)")
        if command:
            p.add_argument("command", nargs=argparse.REMAINDER, help="-- the complete regression command")
        return p

    add("init")
    add("inputs")
    add("regression", command=True)
    add("source-scan")
    add("audit")
    add("build", images=True, artifacts=True)
    add("verify-images")
    add("scan", images=True, artifacts=True)
    add("provenance", images=True)
    add("sign", images=True)
    add("verify", images=True, artifacts=True)
    add("all", images=True, artifacts=True, command=True)
    rescan = sub.add_parser("rescan", help="re-scan retained SBOMs with a fresh database (report only)")
    rescan.add_argument("--sbom", action="append", required=True, help="IMAGE=PATH, e.g. app=evidence/images/app/sbom.spdx.json")
    rescan.add_argument("--out", required=True, type=Path)
    rescan.add_argument("--expect", action="append", help="IMAGE=sha256:... the digest the SBOM must describe")
    rescan.add_argument("--report", type=Path, help=argparse.SUPPRESS)
    sub.add_parser("retained", help="validate and list the retained releases the scheduled re-scan covers")
    audit = sub.choices["audit"]
    audit.add_argument("--fail-on-blocking", action="store_true", help="exit non-zero when the policy evaluator reports a blocking advisory")

    args = parser.parse_args(argv)
    if hasattr(args, "images"):
        args.images = args.images or list(IMAGE_KEYS)
    if hasattr(args, "command") and args.command and args.command[0] == "--":
        args.command = args.command[1:]
    for name in ("out", "repo", "artifacts"):
        if getattr(args, name, None) is not None:
            setattr(args, name, getattr(args, name).resolve())

    handlers = {"init": cmd_init, "inputs": cmd_inputs, "regression": cmd_regression, "source-scan": cmd_source_scan,
                "audit": cmd_audit, "build": cmd_build, "verify-images": cmd_verify_images, "scan": cmd_scan,
                "provenance": cmd_provenance, "sign": cmd_sign}
    try:
        if args.stage == "all":
            return cmd_all(args)
        if args.stage == "verify":
            return cmd_verify(args)
        if args.stage == "rescan":
            return cmd_rescan(args)
        if args.stage == "retained":
            return cmd_retained()
        handlers[args.stage](args)
        return 0
    except ReleaseError as exc:
        print(f"[qualify] FAIL {args.stage}: {exc.code}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
