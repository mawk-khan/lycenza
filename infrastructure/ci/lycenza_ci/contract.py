"""The `main-protection` ruleset contract, read from its one source: ADR 0072
section 5 (pinned by infrastructure/release/tests/test_main_protection_contract.py)."""

from __future__ import annotations

import json
import re
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]
ADR = REPO_ROOT / "docs/architecture/adr/0072-main-branch-protection-publication-control-contract.md"
BLOCK = re.compile(
    r"<!-- e03-ruleset-contract:begin -->\s*```json\n(.*?)\n```\s*<!-- e03-ruleset-contract:end -->",
    re.DOTALL,
)
GITHUB_ACTIONS_APP_ID = 15368
GATE_CONTEXT = "CI gate"


def load_ruleset(text: str | None = None) -> dict:
    blocks = BLOCK.findall(text if text is not None else ADR.read_text(encoding="utf-8"))
    if len(blocks) != 1:
        raise ValueError(f"expected exactly one e03-ruleset-contract block, found {len(blocks)}")
    return json.loads(blocks[0])
