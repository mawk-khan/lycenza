"""PHPUnit JUnit digest: canonical-regression parity evidence (ADR 0072 section 11; E03.1).

Two runs executed the same canonical selection when their `selection_sha256`
(the sorted `class::method` ids of every executed test case) are equal. The
per-class assertion table is published compressed so a remote run can be
compared with a local one class by class without log access.
"""

from __future__ import annotations

import base64
import hashlib
import json
import xml.etree.ElementTree as ET
import zlib
from collections import Counter
from pathlib import Path


def digest(path: Path) -> dict:
    root = ET.parse(path).getroot()
    cases = []
    per_class: Counter[str] = Counter()
    outcomes: Counter[str] = Counter()
    for case in root.iter("testcase"):
        klass = case.get("class") or case.get("classname") or "?"
        name = case.get("name") or "?"
        cases.append(f"{klass}::{name}")
        per_class[klass] += int(case.get("assertions") or 0)
        if case.find("failure") is not None:
            outcomes["failure"] += 1
        elif case.find("error") is not None:
            outcomes["error"] += 1
        elif case.find("skipped") is not None:
            outcomes["skipped"] += 1
        else:
            outcomes["passed"] += 1
    ids = sorted(cases)
    duplicates = sorted(case for case, count in Counter(ids).items() if count > 1)
    table = "\n".join(f"{klass}\t{count}" for klass, count in sorted(per_class.items()))
    return {
        "tests": len(ids),
        "assertions": sum(per_class.values()),
        "outcomes": dict(sorted(outcomes.items())),
        "duplicate_ids": duplicates,
        "selection_sha256": hashlib.sha256("\n".join(ids).encode()).hexdigest(),
        "per_class_zlib_b64": base64.b64encode(zlib.compress(table.encode(), 9)).decode(),
    }


def from_annotations(annotations: list[dict]) -> dict:
    """Reassemble a digest published as a header plus numbered per-class chunks."""
    header = next(json.loads(item["message"]) for item in annotations if item.get("title") == "PHPUnit digest")
    total = header.pop("per_class_chunks")
    chunks = {item["title"]: item["message"] for item in annotations if str(item.get("title", "")).startswith("PHPUnit per-class ")}
    header["per_class_zlib_b64"] = "".join(chunks[f"PHPUnit per-class {number}/{total}"] for number in range(1, total + 1))
    return header


def per_class(encoded: str) -> dict[str, int]:
    table = zlib.decompress(base64.b64decode(encoded)).decode()
    return {klass: int(count) for klass, count in (line.split("\t") for line in table.splitlines() if line)}


def compare(left: dict, right: dict) -> dict:
    a, b = per_class(left["per_class_zlib_b64"]), per_class(right["per_class_zlib_b64"])
    differing = {klass: (a.get(klass), b.get(klass)) for klass in sorted(set(a) | set(b)) if a.get(klass) != b.get(klass)}
    return {"same_selection": left["selection_sha256"] == right["selection_sha256"], "tests": (left["tests"], right["tests"]),
            "assertions": (left["assertions"], right["assertions"]), "classes_differing": differing}


def dumps(value: dict) -> str:
    return json.dumps(value, sort_keys=True)
