"""ADR 0072 section 10 (E03.1): the history-only gitleaks allowlist stays narrow.

Commit 2c92af358 (Phase 0O.8A) committed hand-written DNS-verification test
tokens that 15b123c0 removed 45 minutes later; CI's full-history scan still
finds them. They are exempted ONLY in that one commit, in their one file, for
their exact values. This pins exactly that:

- the commit-scoped entries are exactly the three 2c92af358 fixture files;
- each names one full commit SHA, one anchored path and exact values, all
  under the one `generic-api-key` rule with condition AND;
- every value exists in that commit's file and in NO file of the current tree
  (history only: a value that is still committed needs no history exemption,
  and a reintroduced value is a new finding).

Removing a `commits` scope -- which would exempt the same value in any later
commit -- fails `test_the_history_only_entries_are_exactly_pinned`; the
gitleaks behaviour itself is proven in test_canary_proof.py."""

from __future__ import annotations

import re
import subprocess
import tomllib
import unittest

from lycenza_release.util import REPO_ROOT

HISTORY_COMMIT = "2c92af3583c7420584f0cdf677f46f6ed5dc7288"
HISTORY_PATHS = {
    "apps/platform/tests/Unit/Domains/DnsVerificationTest.php",
    "apps/platform/tests/Unit/Domains/NetDns2DomainResolverTest.php",
    "apps/platform/tests/Feature/Postgres/SchoolDomainDatabaseInvariantsTest.php",
}
PATH = re.compile(r"^\^\(\?:/repo/\)\?(.+)\$$")


def git(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", "-C", str(REPO_ROOT), *args], capture_output=True, text=True, check=False)


def allowlists() -> list[dict]:
    config = tomllib.loads((REPO_ROOT / ".gitleaks.toml").read_text(encoding="utf-8"))
    return [dict(entry, rule=rule["id"]) for rule in config.get("rules", []) for entry in rule.get("allowlists", [])]


class GitleaksHistoryAllowlistTest(unittest.TestCase):
    def test_the_history_only_entries_are_exactly_pinned(self) -> None:
        scoped = [entry for entry in allowlists() if "commits" in entry]
        paths = set()
        for entry in scoped:
            self.assertEqual("generic-api-key", entry["rule"])
            self.assertEqual("AND", entry["condition"])
            self.assertEqual("secret", entry["regexTarget"])
            self.assertEqual([HISTORY_COMMIT], entry["commits"], "exactly one full commit SHA")
            self.assertEqual(1, len(entry["paths"]))
            match = PATH.match(entry["paths"][0])
            self.assertIsNotNone(match, entry["paths"][0])
            paths.add(match.group(1).replace("\\.", "."))
        self.assertEqual(HISTORY_PATHS, paths, "the commit-scoped entries are exactly the 2c92af358 fixture files")
        unscoped = {PATH.match(entry["paths"][0]).group(1).replace("\\.", ".") for entry in allowlists() if "commits" not in entry}
        self.assertEqual(set(), unscoped & HISTORY_PATHS, "a history fixture file must never have an unscoped entry")

    def test_every_history_value_is_in_that_commit_and_nowhere_in_the_tree(self) -> None:
        for entry in (entry for entry in allowlists() if "commits" in entry):
            path = PATH.match(entry["paths"][0]).group(1).replace("\\.", ".")
            then = git("show", f"{HISTORY_COMMIT}:{path}")
            self.assertEqual(0, then.returncode, path)
            for regex in entry["regexes"]:
                self.assertRegex(regex, r"^\^[^*?]+\$$", "an exact value")
                value = regex[1:-1]
                self.assertIn(value, then.stdout, f"{path}: the value exists in {HISTORY_COMMIT[:9]}")
                # The allowlist itself necessarily names the value.
                found = git("grep", "-l", "-F", "-e", value, "HEAD", "--", ".", ":(exclude).gitleaks.toml").stdout.strip()
                self.assertEqual("", found, f"{value[:6]}... is committed in the current tree: not history-only")


if __name__ == "__main__":
    unittest.main()
