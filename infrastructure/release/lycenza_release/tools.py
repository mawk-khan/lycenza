"""Pinned tool execution: every security tool runs from the policy's digest-pinned image."""

from __future__ import annotations

import os
from collections.abc import Sequence
from pathlib import Path

from .util import ReleaseError, run


def docker_run(image: str, args: Sequence[str], *, mounts: Sequence[tuple[Path, str, str]] = (),
               env: dict[str, str] | None = None, entrypoint: str | None = None, workdir: str | None = None,
               network: str | None = None, code: str = "tool_failed", check: bool = True, timeout: int = 3600,
               as_invoker: bool = True, pass_env: Sequence[str] = ()):
    """Run a pinned tool container. `env` values are passed by NAME from this
    process's environment (never on the command line, so they never show in
    a process listing)."""
    if "@sha256:" not in image:
        raise ReleaseError("tool_not_pinned", image.split("@")[0])
    # A private writable /tmp: several tool images keep /tmp root-owned, and
    # tools run as the invoking user, never as root.
    command = ["docker", "run", "--rm", "--pull", "missing", "--tmpfs", "/tmp:rw,exec,mode=1777"]
    if as_invoker:
        command += ["--user", f"{os.getuid()}:{os.getgid()}"]
    if network:
        command += ["--network", network]
    for source, target, mode in mounts:
        command += ["-v", f"{source}:{target}:{mode}"]
    child_env = dict(os.environ)
    for name, value in (env or {}).items():
        child_env[name] = value
        command += ["-e", name]
    for name in pass_env:
        command += ["-e", name]
    if entrypoint is not None:
        command += ["--entrypoint", entrypoint]
    if workdir is not None:
        command += ["-w", workdir]
    command += [image, *args]
    return run(command, env=child_env, code=code, check=check, timeout=timeout)
