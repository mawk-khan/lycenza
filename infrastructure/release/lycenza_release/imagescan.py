"""Image secret checks that do not depend on Dockerfile intent (ADR 0052 section 3.9).

Two scanners over the BUILT artifact:

- config: the image config's Env, Labels, Entrypoint/Cmd and every history
  entry (`docker history --no-trunc` reads exactly these `created_by`
  strings) -- secret-shaped variables with a value, build arguments leaked
  into RUN history, committed development credentials and credential shapes;
- rootfs: the exported filesystem -- `.env` files, committed development
  credentials outside the guards that refuse them, application credential
  shapes and private-key material.

Output is bounded: issue code + location, never the matched value.
"""

from __future__ import annotations

import os
import re
from collections.abc import Iterable
from pathlib import Path
from typing import Any

# The repository's committed local/development values (.env.example files,
# the demo seed password). None may appear in a production image except in
# the code that refuses them.
CANARIES = (
    "Demo1234!",
    "school_os_app_local_only_password",
    "school_os_secret",
    "minioadmin",
    "dev-local-only-token",
    "dev-local-only-context-signing-key-change-me",
    "dev-local-only-contact-lookup-hmac-key-change-me",
    "dev-local-only-statutory-identifier-lookup-hmac-key-change-me",
    # ADR 0054: the committed DEVELOPMENT custom-domain probe key.
    "dev-local-only-domain-probe-key-change-me-0000",
    # ADR 0055: the committed DEVELOPMENT/TEST email suppression key and
    # provider-event secret (.env.example, .ddev, phpunit.xml).
    "dev-local-only-mail-suppression-hmac-key-change-me",
    "dev-local-only-mail-provider-event-secret-change-me",
    "phpunit-only-mail-suppression-hmac-key-0001",
    "phpunit-only-mail-provider-event-secret-0001",
    # ADR 0053: the committed DEVELOPMENT service keys' private seeds (JWK "d"):
    # no image may carry them, not even in a guard (guards match public keys).
    "XZoBPMZruZQzihxKjIBvRRf7cuG1E1zAoq2IWtvQgKI",
    "MhiOdGyb6F13Ihhk_t67ucjsE1sR9lpjRcHBzTcrSBE",
)

# Exact file -> the canaries it may contain: each is a guard that REFUSES the
# value at start-up (ProductionConfigurationGuard; the Gateway's startup check).
CANARY_ALLOWED = {
    "app": {
        "/var/www/app/app/Support/Configuration/ProductionConfigurationGuard.php": {
            c for c in CANARIES if c.startswith("dev-local-only-") or c in {"school_os_secret", "minioadmin"}
        }
    },
    "ai-gateway": {},
}

# Credential SHAPES (never literal values): the application's own personal
# access / partner keys, a Laravel APP_KEY, PEM private-key material.
SHAPES = {
    "application_credential": re.compile(rb"lyc_(?:pat|pk)_[A-Za-z0-9]{16,}"),
    "app_key_value": re.compile(rb"APP_KEY=base64:[A-Za-z0-9+/]{40,}={0,2}"),
    "private_key_material": re.compile(rb"-----BEGIN [A-Z ]*PRIVATE KEY-----\s*[A-Za-z0-9+/=\r\n]{64,}"),
    # ADR 0053: any Ed25519 private JWK (a service signing key) -- dev, test or real.
    "ed25519_private_jwk": re.compile(rb'"crv"\s*:\s*"Ed25519"[^{}]{0,300}?"d"\s*:\s*"[A-Za-z0-9_-]{43}"'),
}

_SECRET_NAME = re.compile(r"(^|_)(PASSWORD|PASSWD|PASSPHRASE|SECRET|SECRETS|TOKEN|TOKENS|KEY|KEYS|CREDENTIAL|CREDENTIALS|PRIVATE)(_|$)")
# Public OpenPGP key FINGERPRINTS the official base images use to verify
# their own source downloads -- public by definition; exact names only.
_PUBLIC_FINGERPRINT_VARS = {"GPG_KEY", "GPG_KEYS"}
_FINGERPRINTS = re.compile(r"^[0-9A-F]{40}( [0-9A-F]{40})*$")
_BUILD_ARG_IN_HISTORY = re.compile(r"^RUN \|\d+ (.*?) (?:/bin/sh -c|\[)")
_ENV_IN_HISTORY = re.compile(r"^ENV (.*)$")

_SKIP_DIRS = {"proc", "sys", "dev"}
_MAX_FILE = 64 * 1024 * 1024


def _secret_assignment(name: str, value: str) -> bool:
    if not value or not _SECRET_NAME.search(name.upper()):
        return False
    return not (name in _PUBLIC_FINGERPRINT_VARS and _FINGERPRINTS.match(value.strip()))


def _pairs(text: str) -> Iterable[tuple[str, str]]:
    for token in re.findall(r"([A-Za-z_][A-Za-z0-9_]*)=(\"[^\"]*\"|'[^']*'|\S*)", text):
        yield token[0], token[1].strip("\"'")


def _text_issues(text: str, where: str) -> list[dict[str, str]]:
    issues = [{"code": "committed_credential", "where": where} for c in CANARIES if c in text]
    raw = text.encode()
    issues += [{"code": code, "where": where} for code, pattern in SHAPES.items() if pattern.search(raw)]
    return issues


def scan_config(config: dict[str, Any]) -> list[dict[str, str]]:
    """Scan an OCI image config (the `config` blob of the manifest)."""
    issues: list[dict[str, str]] = []
    runtime = config.get("config") or {}

    for entry in runtime.get("Env") or []:
        name, _, value = str(entry).partition("=")
        if _secret_assignment(name, value):
            issues.append({"code": "secret_shaped_env", "where": f"env:{name}"})
        issues += _text_issues(str(entry), f"env:{name}")

    for key, value in (runtime.get("Labels") or {}).items():
        if _SECRET_NAME.search(str(key).upper().replace(".", "_").replace("-", "_")):
            issues.append({"code": "secret_shaped_label", "where": f"label:{key}"})
        issues += _text_issues(f"{key}={value}", f"label:{key}")

    for field in ("Entrypoint", "Cmd"):
        issues += _text_issues(" ".join(runtime.get(field) or []), field.lower())

    for index, entry in enumerate(config.get("history") or []):
        created_by = str(entry.get("created_by", ""))
        where = f"history:{index}"
        issues += _text_issues(created_by, where)
        build_args = _BUILD_ARG_IN_HISTORY.match(created_by)
        if build_args:
            issues += [{"code": "secret_build_arg", "where": f"{where}:{n}"} for n, v in _pairs(build_args.group(1)) if _secret_assignment(n, v)]
        env = _ENV_IN_HISTORY.match(created_by)
        if env:
            issues += [{"code": "secret_shaped_env", "where": f"{where}:{n}"} for n, v in _pairs(env.group(1)) if _secret_assignment(n, v)]

    return _unique(issues)


def scan_rootfs(root: Path, image: str) -> list[dict[str, str]]:
    """Scan an exported image filesystem rooted at `root`."""
    issues: list[dict[str, str]] = []
    allowed = CANARY_ALLOWED.get(image, {})
    canaries = [c.encode() for c in CANARIES]

    for directory, subdirs, files in os.walk(root):
        relative = os.path.relpath(directory, root)
        rel_dir = "" if relative == "." else "/" + relative.replace(os.sep, "/")
        if directory == str(root):
            subdirs[:] = [d for d in subdirs if d not in _SKIP_DIRS]
        for name in files:
            path = Path(directory) / name
            image_path = f"{rel_dir}/{name}"
            if name == ".env" or name.startswith(".env."):
                issues.append({"code": "dotenv_file", "where": image_path})
            if path.is_symlink() or not path.is_file():
                continue
            try:
                if path.stat().st_size > _MAX_FILE:
                    continue
                data = path.read_bytes()
            except OSError:
                issues.append({"code": "unreadable_file", "where": image_path})
                continue
            for canary, text in zip(canaries, CANARIES, strict=False):
                if canary in data and text not in allowed.get(image_path, set()):
                    issues.append({"code": "committed_credential", "where": image_path})
            for code, pattern in SHAPES.items():
                if pattern.search(data):
                    issues.append({"code": code, "where": image_path})

    return _unique(issues)


def _unique(issues: list[dict[str, str]]) -> list[dict[str, str]]:
    seen, result = set(), []
    for issue in issues:
        key = (issue["code"], issue["where"])
        if key not in seen:
            seen.add(key)
            result.append(issue)
    return result


def apply_allowlist(issues: list[dict[str, str]], entries: list[dict[str, str]], root: Path, image: str) -> tuple[list[dict[str, str]], list[dict[str, str]]]:
    """Split rootfs issues into (remaining, allowlisted). A match needs image, code, path and the file's exact sha256."""
    from .util import sha256_file

    remaining, allowed = [], []
    for issue in issues:
        entry = next((e for e in entries if e["image"] == image and e["code"] == issue["code"] and e["path"] == issue["where"]), None)
        target = root / issue["where"].lstrip("/")
        if entry is not None and target.is_file() and not target.is_symlink() and sha256_file(target) == entry["sha256"]:
            allowed.append({**issue, "allowlisted": entry["reason"]})
        else:
            remaining.append(issue)
    return remaining, allowed


def config_from_docker(tag: str) -> dict[str, Any]:
    """The same shape as an OCI config, from a locally loaded image:
    `docker image inspect` (Env, Labels, Entrypoint, Cmd) and
    `docker history --no-trunc` (every layer's created_by)."""
    import json as _json

    from .util import run

    inspected = _json.loads(run(["docker", "image", "inspect", tag], code="image_inspect_failed").stdout)[0]
    history = run(["docker", "history", "--no-trunc", "--format", "{{json .CreatedBy}}", tag], code="image_inspect_failed").stdout.decode()
    runtime = inspected.get("Config") or {}
    return {
        "config": {k: runtime.get(k) for k in ("Env", "Labels", "Entrypoint", "Cmd")},
        "history": [{"created_by": _json.loads(line)} for line in reversed(history.splitlines()) if line.strip()],
    }
