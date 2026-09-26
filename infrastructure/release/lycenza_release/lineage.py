"""Releasable source = a commit reachable from protected `main` (ADR 0052 section 3.15)."""

from __future__ import annotations

from pathlib import Path

from .util import COMMIT, run


def on_protected_branch(repo: Path, commit: str, protected_ref: str) -> bool:
    if not COMMIT.match(commit):
        return False
    exists = run(["git", "-C", str(repo), "cat-file", "-e", f"{commit}^{{commit}}"], check=False)
    ref = run(["git", "-C", str(repo), "rev-parse", "--verify", "--quiet", f"{protected_ref}^{{commit}}"], check=False)
    if exists.returncode != 0 or ref.returncode != 0:
        return False
    return run(["git", "-C", str(repo), "merge-base", "--is-ancestor", commit, protected_ref], check=False).returncode == 0
