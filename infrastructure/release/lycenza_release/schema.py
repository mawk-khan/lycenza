"""A deliberately small JSON Schema (draft 2020-12 subset) validator.

The release tooling uses only the Python standard library (no dependency
of its own to pin, audit or trust). This validator implements exactly the
keywords the repository's schemas use and FAILS CLOSED on any other
keyword, so a schema can never rely on a rule that is silently ignored.
"""

from __future__ import annotations

import re
from datetime import date
from typing import Any

SUPPORTED = {
    "$schema", "$id", "title", "description", "$comment", "$defs", "$ref",
    "type", "properties", "required", "additionalProperties", "items",
    "enum", "const", "pattern", "minLength", "maxLength", "minItems",
    "maxItems", "uniqueItems", "minimum", "maximum", "format", "oneOf",
}

_TYPES = {
    "object": dict,
    "array": list,
    "string": str,
    "boolean": bool,
    "null": type(None),
}


class SchemaError(Exception):
    """The schema itself uses an unsupported keyword or is malformed."""


def validate(instance: Any, schema: dict[str, Any]) -> list[str]:
    """Return a list of violations (empty when valid). Paths are JSON-pointer-like."""
    errors: list[str] = []
    _validate(instance, schema, schema, "$", errors)
    return errors


def _type_ok(value: Any, expected: str) -> bool:
    if expected == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if expected == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    if expected == "boolean":
        return isinstance(value, bool)
    return isinstance(value, _TYPES[expected])


def _resolve(ref: str, root: dict[str, Any]) -> dict[str, Any]:
    if not ref.startswith("#/$defs/"):
        raise SchemaError(f"unsupported $ref {ref!r}")
    name = ref[len("#/$defs/"):]
    try:
        return root["$defs"][name]
    except KeyError as exc:
        raise SchemaError(f"unknown $ref {ref!r}") from exc


def _validate(value: Any, schema: dict[str, Any], root: dict[str, Any], path: str, errors: list[str]) -> None:
    unknown = set(schema) - SUPPORTED
    if unknown:
        raise SchemaError(f"unsupported schema keyword(s) {sorted(unknown)} at {path}")

    if "$ref" in schema:
        _validate(value, _resolve(schema["$ref"], root), root, path, errors)

    if "oneOf" in schema:
        matches = sum(1 for sub in schema["oneOf"] if not _collect(value, sub, root, path))
        if matches != 1:
            errors.append(f"{path}: must match exactly one allowed shape")
            return

    expected = schema.get("type")
    if expected is not None:
        options = expected if isinstance(expected, list) else [expected]
        if not any(_type_ok(value, t) for t in options):
            errors.append(f"{path}: must be of type {'/'.join(options)}")
            return

    if "const" in schema and value != schema["const"]:
        errors.append(f"{path}: must equal {schema['const']!r}")
    if "enum" in schema and value not in schema["enum"]:
        errors.append(f"{path}: must be one of {schema['enum']}")

    if isinstance(value, str):
        if "minLength" in schema and len(value) < schema["minLength"]:
            errors.append(f"{path}: shorter than {schema['minLength']}")
        if "maxLength" in schema and len(value) > schema["maxLength"]:
            errors.append(f"{path}: longer than {schema['maxLength']}")
        if "pattern" in schema and re.search(schema["pattern"], value) is None:
            errors.append(f"{path}: does not match the required pattern")
        if schema.get("format") == "date":
            try:
                date.fromisoformat(value)
                if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
                    raise ValueError
            except ValueError:
                errors.append(f"{path}: must be an ISO date (YYYY-MM-DD)")
        elif "format" in schema:
            raise SchemaError(f"unsupported format {schema['format']!r} at {path}")

    if isinstance(value, (int, float)) and not isinstance(value, bool):
        if "minimum" in schema and value < schema["minimum"]:
            errors.append(f"{path}: below {schema['minimum']}")
        if "maximum" in schema and value > schema["maximum"]:
            errors.append(f"{path}: above {schema['maximum']}")

    if isinstance(value, list):
        if "minItems" in schema and len(value) < schema["minItems"]:
            errors.append(f"{path}: fewer than {schema['minItems']} item(s)")
        if "maxItems" in schema and len(value) > schema["maxItems"]:
            errors.append(f"{path}: more than {schema['maxItems']} item(s)")
        if schema.get("uniqueItems") and len({repr(v) for v in value}) != len(value):
            errors.append(f"{path}: items must be unique")
        if "items" in schema:
            for i, item in enumerate(value):
                _validate(item, schema["items"], root, f"{path}[{i}]", errors)

    if isinstance(value, dict):
        for key in schema.get("required", []):
            if key not in value:
                errors.append(f"{path}: missing required property {key!r}")
        properties = schema.get("properties", {})
        additional = schema.get("additionalProperties", True)
        for key, item in value.items():
            if key in properties:
                _validate(item, properties[key], root, f"{path}.{key}", errors)
            elif additional is False:
                errors.append(f"{path}: unexpected property {key!r}")
            elif isinstance(additional, dict):
                _validate(item, additional, root, f"{path}.{key}", errors)


def _collect(value: Any, schema: dict[str, Any], root: dict[str, Any], path: str) -> list[str]:
    errors: list[str] = []
    _validate(value, schema, root, path, errors)
    return errors
