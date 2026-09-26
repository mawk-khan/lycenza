"""Shared helpers: hashing, canonical JSON, time, bounded errors, subprocesses."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
from collections.abc import Sequence
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[3]
RELEASE_DIR = REPO_ROOT / "infrastructure" / "release"

SHA256_HEX = re.compile(r"^[0-9a-f]{64}$")
DIGEST = re.compile(r"^sha256:[0-9a-f]{64}$")
COMMIT = re.compile(r"^[0-9a-f]{40}$")
# A bounded, machine-readable failure code -- never free text from a tool.
CODE = re.compile(r"^[a-z][a-z0-9_]{2,63}$")


class ReleaseError(Exception):
    """A fail-closed stop with a bounded code. The message never carries tool output."""

    def __init__(self, code: str, detail: str = "") -> None:
        if not CODE.match(code):
            code = "unclassified"
        super().__init__(f"{code}: {detail}" if detail else code)
        self.code = code
        self.detail = detail


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def load_json(path: Path) -> Any:
    try:
        with path.open(encoding="utf-8") as handle:
            return json.load(handle)
    except FileNotFoundError as exc:
        raise ReleaseError("evidence_missing", path.name) from exc
    except (json.JSONDecodeError, UnicodeDecodeError) as exc:
        raise ReleaseError("evidence_malformed", path.name) from exc


def dump_json(data: Any) -> str:
    return json.dumps(data, indent=2, sort_keys=True, ensure_ascii=True) + "\n"


def write_json(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(dump_json(data), encoding="utf-8")


def utc_now() -> datetime:
    return datetime.now(UTC).replace(microsecond=0)


def iso(moment: datetime) -> str:
    return moment.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_time(value: str) -> datetime:
    """RFC 3339 timestamps as emitted by Grype/Syft/this tooling (Z or offset, optional fraction)."""
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    text = re.sub(r"\.(\d{6})\d+", r".\1", text)
    moment = datetime.fromisoformat(text)
    if moment.tzinfo is None:
        raise ValueError("timestamp without a timezone")
    return moment.astimezone(UTC)


def run(args: Sequence[str], *, cwd: Path | None = None, env: dict[str, str] | None = None,
        input_bytes: bytes | None = None, code: str = "tool_failed", check: bool = True,
        timeout: int = 3600) -> subprocess.CompletedProcess[bytes]:
    """Run a command; on failure raise a bounded ReleaseError (tool output is not echoed)."""
    try:
        result = subprocess.run(list(args), cwd=cwd, env=env, input=input_bytes,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise ReleaseError(code, Path(args[0]).name) from exc
    if check and result.returncode != 0:
        raise ReleaseError(code, f"{Path(args[0]).name} exited {result.returncode}")
    return result
