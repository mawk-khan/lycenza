"""ADR 0072 (E03.0): the `main-protection` ruleset contract is pinned.

ADR 0072 section 5 carries the machine-readable ruleset that E03.2 applies to
GitHub and read-backs compare against. This pins its security invariant
(section 4) so the contract cannot drift silently: the default branch, active
enforcement, no bypass actors, deletion and force-push blocked, linear
history, and exactly one required context, `CI gate`, sourced from the GitHub
Actions app -- and no pull-request rule during active development (section
16). It checks the document only; it never claims a ruleset is live."""

from __future__ import annotations

import json
import re
import unittest

from lycenza_release.util import REPO_ROOT

ADR = REPO_ROOT / "docs/architecture/adr/0072-main-branch-protection-publication-control-contract.md"
BLOCK = re.compile(
    r"<!-- e03-ruleset-contract:begin -->\s*```json\n(.*?)\n```\s*<!-- e03-ruleset-contract:end -->",
    re.DOTALL,
)
GITHUB_ACTIONS_APP_ID = 15368


def load_contract() -> dict:
    blocks = BLOCK.findall(ADR.read_text(encoding="utf-8"))
    if len(blocks) != 1:
        raise AssertionError(f"expected exactly one e03-ruleset-contract block, found {len(blocks)}")
    return json.loads(blocks[0])


class MainProtectionContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.contract = load_contract()
        self.rules = {rule["type"]: rule for rule in self.contract["rules"]}

    def test_targets_the_default_branch_actively_with_no_bypass(self) -> None:
        self.assertEqual("main-protection", self.contract["name"])
        self.assertEqual("branch", self.contract["target"])
        self.assertEqual("active", self.contract["enforcement"])
        self.assertEqual({"include": ["~DEFAULT_BRANCH"], "exclude": []}, self.contract["conditions"]["ref_name"])
        self.assertEqual([], self.contract["bypass_actors"])

    def test_exactly_the_contracted_rules(self) -> None:
        self.assertEqual(len(self.rules), len(self.contract["rules"]), "a rule type appears twice")
        self.assertEqual(
            {"deletion", "non_fast_forward", "required_linear_history", "required_status_checks"},
            set(self.rules),
        )
        # Active development: no PR rule (ADR 0072 section 16). Production stage changes the contract.
        self.assertNotIn("pull_request", self.rules)

    def test_one_required_context_sourced_from_github_actions(self) -> None:
        parameters = self.rules["required_status_checks"]["parameters"]
        self.assertEqual([{"context": "CI gate", "integration_id": GITHUB_ACTIONS_APP_ID}], parameters["required_status_checks"])
        self.assertIs(False, parameters["do_not_enforce_on_create"])
        self.assertIs(False, parameters["strict_required_status_checks_policy"])


if __name__ == "__main__":
    unittest.main()
