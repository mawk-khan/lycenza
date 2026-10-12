"""ADR 0072 section 30 (E03.3): the publication instructions match protected `main`.

The `/publish-gate` skill and CLAUDE.md rule 96 are what an agent or developer
follows to publish. They must describe the exact-SHA flow (integration/**,
`CI gate`, `publication-check --push`), never a direct push to `main`, and
must not present the 7 allowlisted historical gitleaks fixtures as
outstanding findings."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci.contract import REPO_ROOT  # noqa: E402

SKILL = REPO_ROOT / ".claude/skills/publish-gate/SKILL.md"
CLAUDE = REPO_ROOT / "CLAUDE.md"


class PublicationInstructionsTest(unittest.TestCase):
    def test_publish_gate_describes_the_protected_exact_sha_flow(self) -> None:
        text = SKILL.read_text(encoding="utf-8")
        for required in ("integration/<slice>", "CI gate", "publication-check", "--push", "15368", "e16_last_valid_day"):
            self.assertIn(required, text)
        for stale in ("Commit and push to `main`", "pushed directly to `main`", "pushing a slice to main", "pre-existing"):
            self.assertNotIn(stale, text)

    def test_publish_gate_states_the_gitleaks_history_allowlist_correctly(self) -> None:
        text = SKILL.read_text(encoding="utf-8")
        self.assertIn("expected to **pass**", text)
        self.assertIn("still fails", text)

    def test_rule_96_names_the_wrapper_and_the_ruleset(self) -> None:
        text = CLAUDE.read_text(encoding="utf-8")
        start = text.index("96. **`main` moves only to an exact SHA")
        rule = text[start:start + 2500]
        self.assertIn("publication-check --candidate <sha> --push", rule)
        self.assertIn("main-protection", rule)
        self.assertNotIn("discipline, not enforcement", rule)


if __name__ == "__main__":
    unittest.main()
