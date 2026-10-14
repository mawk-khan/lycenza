"""ADR 0072 section 31 (CI sharding Option A): the deterministic shard plan is
complete and disjoint, a shard's execution must equal its plan, and the union
of one run's shards must be the canonical inventory exactly once."""

from __future__ import annotations

import json
import random
import sys
import tempfile
import unittest
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci import junit  # noqa: E402
from lycenza_ci import shard  # noqa: E402
from lycenza_ci.cli import main  # noqa: E402
from lycenza_ci.contract import REPO_ROOT  # noqa: E402

NS = "https://xml.phpunit.de/testSuite"


def inventory_xml(classes: dict[str, int], root: str = "/var/www/app") -> str:
    body = []
    for name, count in classes.items():
        path = "tests/" + name.replace("Tests\\", "").replace("\\", "/") + ".php"
        methods = "".join(f'<testMethod id="{name}::t{i}" name="t{i}"/>' for i in range(count))
        body.append(f'<testClass name="{name}" file="{root}/{path}">{methods}</testClass>')
    return f'<?xml version="1.0"?><testSuite xmlns="{NS}"><tests>{"".join(body)}</tests><groups/></testSuite>'


def junit_xml(cases: dict[str, int], fail: str | None = None) -> str:
    rows = []
    for klass, count in cases.items():
        for i in range(count):
            inner = "<failure>boom</failure>" if fail == f"{klass}::t{i}" else ""
            rows.append(f'<testcase name="t{i}" class="{klass}" assertions="2">{inner}</testcase>')
    return f'<?xml version="1.0"?><testsuites><testsuite name="s">{"".join(rows)}</testsuite></testsuites>'


CLASSES = {f"Tests\\Feature\\M{m}\\C{c}Test": (m * 7 + c * 3) % 23 + 1 for m in range(6) for c in range(9)}
CLASSES.update({f"Tests\\Unit\\U{c}Test": c % 5 + 1 for c in range(15)})


class Workspace:
    def __init__(self) -> None:
        self._dir = tempfile.TemporaryDirectory()
        self.root = Path(self._dir.name)

    def write(self, name: str, text: str) -> Path:
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
        return path

    def close(self) -> None:
        self._dir.cleanup()


class ShardPlanTest(unittest.TestCase):
    def setUp(self) -> None:
        self.ws = Workspace()
        self.files = shard.read_inventory(self.ws.write("list.xml", inventory_xml(CLASSES)))

    def tearDown(self) -> None:
        self.ws.close()

    def test_shard_arguments_are_validated(self) -> None:
        self.assertEqual((2, 4), shard.parse_shard("2/4"))
        self.assertEqual((1, 1), shard.parse_shard("1/1"))
        for bad in ("0/4", "5/4", "2", "a/b", "-1/4", "2/4/1", "02/4", "1/0", " /4", "1/33", "2 / 4"):
            with self.assertRaises(shard.ShardError, msg=bad):
                shard.parse_shard(bad)

    def test_every_file_is_in_exactly_one_shard_for_every_total(self) -> None:
        for total in range(1, 12):
            shards = shard.partition(self.files, total)
            paths = Counter(item.path for members in shards for item in members)
            self.assertEqual({item.path for item in self.files}, set(paths), total)
            self.assertEqual({1}, set(paths.values()), total)
            plans = [shard.plan(self.files, index, total) for index in range(1, total + 1)]
            union = Counter()
            for plan in plans:
                self.assertFalse(set(union) & set(plan["classes"]), "disjoint")
                union.update(plan["classes"])
            self.assertEqual(CLASSES, dict(union), "complete, with the case counts")
            self.assertEqual(sum(CLASSES.values()), sum(plan["shard_tests"][plan["shard"] - 1] for plan in plans))

    def test_the_plan_is_deterministic_and_order_independent(self) -> None:
        reference = [shard.plan(self.files, index, 4) for index in range(1, 5)]
        items = list(CLASSES.items())
        random.Random(7).shuffle(items)
        shuffled = shard.read_inventory(self.ws.write("shuffled.xml", inventory_xml(dict(items))))
        self.assertEqual(shard.inventory_digest(self.files), shard.inventory_digest(shuffled))
        for index in range(1, 5):
            again = shard.plan(self.files, index, 4)
            self.assertEqual(reference[index - 1], again)
            other = shard.plan(shuffled, index, 4)
            self.assertEqual(reference[index - 1]["plan_sha256"], other["plan_sha256"])
            self.assertEqual(sorted(reference[index - 1]["files"]), sorted(other["files"]))

    def test_a_shard_keeps_the_canonical_order(self) -> None:
        order = [item.path for item in self.files]
        for index in range(1, 5):
            members = shard.plan(self.files, index, 4)["files"]
            self.assertEqual(sorted(members, key=order.index), members)

    def test_the_partition_is_balanced_by_test_count(self) -> None:
        loads = shard.plan(self.files, 1, 4)["shard_tests"]
        self.assertLessEqual(max(loads) - min(loads), max(CLASSES.values()))

    def test_unshardable_inventories_are_refused(self) -> None:
        cases = {
            "outside the container root": inventory_xml({"Tests\\Unit\\XTest": 1}, root="/elsewhere"),
            "empty": f'<?xml version="1.0"?><testSuite xmlns="{NS}"><tests/></testSuite>',
            "a .phpt entry": f'<?xml version="1.0"?><testSuite xmlns="{NS}"><tests><phpt file="/var/www/app/tests/a.phpt"/></tests></testSuite>',
            "a class without cases": f'<?xml version="1.0"?><testSuite xmlns="{NS}"><tests><testClass name="X" file="/var/www/app/tests/X.php"/></tests></testSuite>',
            "not an inventory": "<other/>",
            "not XML": "<<",
        }
        for label, text in cases.items():
            with self.assertRaises(shard.ShardError, msg=label):
                shard.read_inventory(self.ws.write(f"{abs(hash(label))}.xml", text))

    def test_a_file_with_two_classes_is_refused(self) -> None:
        text = (f'<?xml version="1.0"?><testSuite xmlns="{NS}"><tests>'
                '<testClass name="A" file="/var/www/app/tests/X.php"><testMethod id="A::a" name="a"/></testClass>'
                '<testClass name="B" file="/var/www/app/tests/X.php"><testMethod id="B::b" name="b"/></testClass>'
                '</tests></testSuite>')
        files = shard.read_inventory(self.ws.write("two.xml", text))
        with self.assertRaises(shard.ShardError):
            shard.plan(files, 1, 2)

    def test_the_config_replaces_only_the_test_suites(self) -> None:
        base = (REPO_ROOT / "apps/platform/phpunit.xml").read_text(encoding="utf-8")
        members = shard.plan(self.files, 2, 4)["files"]
        rendered = shard.render_config(base, members, 2, 4)
        head, tail = base.split("<testsuites>")[0], base.split("</testsuites>")[1]
        self.assertTrue(rendered.startswith(head.rstrip()), "everything before <testsuites> is verbatim")
        self.assertTrue(rendered.endswith(tail), "everything after </testsuites> (the forced <php> block) is verbatim")
        self.assertIn('force="true"', rendered)
        self.assertNotIn("<directory>tests/", rendered)
        self.assertEqual(members, [line.strip()[6:-7] for line in rendered.splitlines() if line.strip().startswith("<file>tests/")])
        with self.assertRaises(shard.ShardError):
            shard.render_config(base, ["../etc/passwd.php"], 1, 1)
        with self.assertRaises(shard.ShardError):
            shard.render_config("<phpunit/>", members, 1, 1)


class ShardExecutionTest(unittest.TestCase):
    def setUp(self) -> None:
        self.ws = Workspace()
        self.list_text = inventory_xml(CLASSES)
        self.files = shard.read_inventory(self.ws.write("list.xml", self.list_text))
        self.plans = {index: shard.plan(self.files, index, 4) for index in range(1, 5)}

    def tearDown(self) -> None:
        self.ws.close()

    def shard_dir(self, index: int, classes: dict[str, int] | None = None, fail: str | None = None, report: dict | None = None) -> Path:
        directory = self.ws.root / "shards" / f"phpunit-shard-{index}"
        self.ws.write(f"shards/phpunit-shard-{index}/phpunit-list.xml", self.list_text)
        self.ws.write(f"shards/phpunit-shard-{index}/phpunit-junit.xml", junit_xml(classes if classes is not None else self.plans[index]["classes"], fail))
        self.ws.write(f"shards/phpunit-shard-{index}/shard-report.json", json.dumps(report if report is not None else self.plans[index]))
        return directory

    def bundles(self) -> list[shard.ShardBundle]:
        result = []
        for directory in sorted((self.ws.root / "shards").iterdir()):
            result.append(shard.ShardBundle(json.loads((directory / "shard-report.json").read_text()),
                                            shard.read_inventory(directory / "phpunit-list.xml"), directory / "phpunit-junit.xml"))
        return result

    def test_a_shard_that_ran_its_plan_verifies(self) -> None:
        path = self.ws.write("j.xml", junit_xml(self.plans[2]["classes"]))
        summary = shard.verify(self.plans[2], path)
        self.assertEqual(self.plans[2]["shard_tests"][1], summary["tests"])

    def test_a_shard_that_did_not_run_its_plan_fails(self) -> None:
        planned = self.plans[2]["classes"]
        first = next(iter(planned))
        fewer = dict(planned, **{first: planned[first] - 1}) if planned[first] > 1 else {k: v for k, v in planned.items() if k != first}
        foreign = dict(planned, **{next(iter(self.plans[3]["classes"])): 1})
        variants = {
            "a missing case (e.g. --filter, a dropped data set)": junit_xml(fewer),
            "a test from another shard": junit_xml(foreign),
            "a failing test": junit_xml(planned, fail=f"{first}::0".replace("::0", "::t0")),
            "a truncated report (PHP fatal mid-run)": junit_xml(planned)[:200],
        }
        for label, text in variants.items():
            with self.assertRaises(shard.ShardError, msg=label):
                shard.verify(self.plans[2], self.ws.write(f"{abs(hash(label))}.xml", text))
        with self.assertRaises(shard.ShardError, msg="no report at all"):
            shard.verify(self.plans[2], self.ws.root / "absent.xml")

    def test_the_union_of_all_shards_is_the_inventory_once(self) -> None:
        for index in range(1, 5):
            self.shard_dir(index)
        result = shard.coverage(self.bundles())
        self.assertEqual(sum(CLASSES.values()), result["union"]["tests"])
        unsharded = self.ws.write("unsharded.xml", junit_xml(CLASSES))
        self.assertEqual(junit.digest(unsharded)["selection_sha256"], result["union"]["selection_sha256"],
                         "the union digest equals an unsharded run of the same selection")
        self.assertEqual(junit.digest(unsharded)["per_class_zlib_b64"], result["union"]["per_class_zlib_b64"])

    def test_coverage_fails_closed(self) -> None:
        def run(setup) -> None:
            if (self.ws.root / "shards").exists():
                for directory in (self.ws.root / "shards").iterdir():
                    for file in directory.iterdir():
                        file.unlink()
                    directory.rmdir()
            setup()
            with self.assertRaises(shard.ShardError):
                shard.coverage(self.bundles() if (self.ws.root / "shards").exists() else [])

        run(lambda: None)  # no shard output at all
        run(lambda: [self.shard_dir(i) for i in (1, 2, 4)])  # a missing shard
        run(lambda: [self.shard_dir(i) for i in (1, 2, 3)])  # the last shard missing
        run(lambda: [self.shard_dir(i) for i in (1, 2, 3, 4)] and self.shard_dir(4, fail=f"{next(iter(self.plans[4]['classes']))}::t0"))
        tampered = dict(self.plans[3], classes={k: v for k, v in list(self.plans[3]["classes"].items())[1:]})
        run(lambda: [self.shard_dir(i) for i in (1, 2, 4)] and self.shard_dir(3, classes=tampered["classes"], report=tampered))
        doubled = dict(self.plans[1], total=4)
        run(lambda: [self.shard_dir(i) for i in (1, 2, 3, 4)] and self.shard_dir(2, classes=doubled["classes"], report=dict(doubled, shard=2)))
        other_total = shard.plan(self.files, 1, 3)
        run(lambda: [self.shard_dir(i) for i in (2, 3, 4)] and self.shard_dir(1, classes=other_total["classes"], report=other_total))

    def test_shards_planned_from_different_inventories_fail(self) -> None:
        for index in range(1, 5):
            self.shard_dir(index)
        drifted = dict(CLASSES)
        drifted[next(iter(drifted))] += 1
        self.ws.write("shards/phpunit-shard-3/phpunit-list.xml", inventory_xml(drifted))
        with self.assertRaises(shard.ShardError):
            shard.coverage(self.bundles())

    def test_the_cli_round_trip(self) -> None:
        listing = self.ws.write("cli/list.xml", self.list_text)
        out = self.ws.root / "cli"
        config = REPO_ROOT / "apps/platform/phpunit.xml"
        self.assertEqual(0, main(["shard", "plan", "--inventory", str(listing), "--shard", "3/4", "--config", str(config),
                                  "--write-config", str(out / "phpunit.shard.xml"), "--plan-out", str(out / "plan.json")]))
        plan = json.loads((out / "plan.json").read_text())
        self.assertEqual(self.plans[3], plan)
        self.assertIn('<testsuite name="Shard 3 of 4">', (out / "phpunit.shard.xml").read_text())
        self.ws.write("cli/junit.xml", junit_xml(plan["classes"]))
        self.assertEqual(0, main(["shard", "verify", "--plan", str(out / "plan.json"), "--junit", str(out / "junit.xml"),
                                  "--report-out", str(out / "report.json")]))
        self.assertEqual(1, main(["shard", "plan", "--inventory", str(listing), "--shard", "5/4"]))
        self.assertEqual(1, main(["shard", "verify", "--plan", str(out / "plan.json"), "--junit", str(out / "absent.xml")]))
        self.assertEqual(1, main(["shard", "coverage", "--dir", str(self.ws.root / "nothing-here")]))


class RepositoryInventoryTest(unittest.TestCase):
    """The precondition the planner relies on, checked on the real tree: one test class per test file."""

    def test_every_test_file_declares_exactly_one_class(self) -> None:
        offenders = []
        for path in (REPO_ROOT / "apps/platform/tests").rglob("*Test.php"):
            text = path.read_text(encoding="utf-8")
            declarations = [line for line in text.splitlines() if line.startswith(("class ", "final class ", "abstract class ", "readonly class ", "final readonly class "))]
            if len(declarations) != 1:
                offenders.append((str(path.relative_to(REPO_ROOT)), len(declarations)))
        self.assertEqual([], offenders)

    def test_the_generated_config_is_never_committed(self) -> None:
        ignore = (REPO_ROOT / "apps/platform/.gitignore").read_text(encoding="utf-8").splitlines()
        self.assertIn("/" + shard.SHARD_CONFIG, ignore)


if __name__ == "__main__":
    unittest.main()
