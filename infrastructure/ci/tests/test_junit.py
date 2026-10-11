"""ADR 0072 section 11 (E03.1): the PHPUnit JUnit digest used as parity evidence."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from lycenza_ci.junit import compare, digest, from_annotations, per_class  # noqa: E402

REPORT = """<?xml version="1.0"?>
<testsuites><testsuite name="all">
 <testsuite name="Tests\\\\A"><testcase class="Tests\\\\A" name="one" assertions="3"/><testcase class="Tests\\\\A" name="two" assertions="2"><skipped/></testcase></testsuite>
 <testsuite name="Tests\\\\B"><testcase class="Tests\\\\B" name="three" assertions="{b}"><failure>x</failure></testcase></testsuite>
</testsuite></testsuites>
"""


def write(text: str) -> Path:
    handle = tempfile.NamedTemporaryFile("w", suffix=".xml", delete=False)
    handle.write(text)
    handle.close()
    return Path(handle.name)


class JunitDigestTest(unittest.TestCase):
    def test_digest_counts_selection_and_outcomes(self) -> None:
        value = digest(write(REPORT.format(b=4)))
        self.assertEqual(3, value["tests"])
        self.assertEqual(9, value["assertions"])
        self.assertEqual({"failure": 1, "passed": 1, "skipped": 1}, value["outcomes"])
        self.assertEqual({"Tests\\\\A": 5, "Tests\\\\B": 4}, per_class(value["per_class_zlib_b64"]))
        self.assertEqual([], value["duplicate_ids"])

    def test_compare_finds_the_class_that_differs_on_the_same_selection(self) -> None:
        left, right = digest(write(REPORT.format(b=4))), digest(write(REPORT.format(b=7)))
        result = compare(left, right)
        self.assertTrue(result["same_selection"])
        self.assertEqual({"Tests\\\\B": (4, 7)}, result["classes_differing"])

    def test_a_chunked_annotation_round_trips(self) -> None:
        value = digest(write(REPORT.format(b=4)))
        table = value["per_class_zlib_b64"]
        header = {key: item for key, item in value.items() if key != "per_class_zlib_b64"}
        chunks = [table[i:i + 5] for i in range(0, len(table), 5)]
        header["per_class_chunks"] = len(chunks)
        annotations = [{"title": "PHPUnit digest", "message": json.dumps(header)}]
        annotations += [{"title": f"PHPUnit per-class {n}/{len(chunks)}", "message": c} for n, c in enumerate(chunks, start=1)]
        self.assertEqual(value, from_annotations(list(reversed(annotations))))

    def test_a_different_selection_is_detected(self) -> None:
        other = REPORT.format(b=4).replace('name="three"', 'name="four"')
        self.assertFalse(compare(digest(write(REPORT.format(b=4))), digest(write(other)))["same_selection"])


if __name__ == "__main__":
    unittest.main()
