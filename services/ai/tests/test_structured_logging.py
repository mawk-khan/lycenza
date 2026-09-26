"""Phase 0O.5A (ADR 0051 §5, §8): Gateway logs are structured JSON with
the shared field catalog, INFO events are emitted, extra fields are
serialized, and G3 redaction holds (no prompt, output, body, credential or
context token)."""

from __future__ import annotations

import io
import json
import logging

from app.core.logging import FIELDS, JsonFormatter, configure_logging, sanitize, scrub


def _record(
    msg: str, level: int = logging.INFO, name: str = "app.main", args=None, exc_info=None, **extra
):
    record = logging.LogRecord(name, level, "main.py", 10, msg, args, exc_info)
    for key, value in extra.items():
        setattr(record, key, value)
    return record


def _line(record: logging.LogRecord) -> dict:
    return json.loads(JsonFormatter("production").format(record))


def test_an_event_is_one_json_object_with_the_fixed_schema() -> None:
    line = _line(
        _record(
            "ai.complete",
            request_id="req-0001abcd",
            trace_id="0af7651916cd43dd8448eb211c80319c",
            school_id="11111111-1111-1111-1111-111111111111",
            agent="school-echo",
            outcome="succeeded",
            latency_ms=12,
            input_tokens=5,
            output_tokens=7,
        )
    )

    assert line["event_code"] == "ai.complete"
    assert line["service"] == "ai-gateway"
    assert line["process_role"] == "gateway"
    assert line["environment"] == "production"
    assert line["request_id"] == "req-0001abcd"
    assert line["trace_id"] == "0af7651916cd43dd8448eb211c80319c"
    assert line["outcome"] == "succeeded"
    assert line["ctx"] == {
        "agent": "school-echo",
        "latency_ms": 12,
        "input_tokens": 5,
        "output_tokens": 7,
    }
    assert set(line) <= set(FIELDS)
    assert "message" not in line


def test_g3_content_and_credentials_are_never_logged() -> None:
    canaries = {
        "prompt": "canary-prompt-text",
        "output": "canary-model-output",
        "text": "canary-completion-text",
        "body": "canary-request-body",
        "context_token": "canary-context-token",
        "service_token": "canary-service-token",
        "authorization": "Bearer canary-bearer",
    }
    line = JsonFormatter("production").format(_record("ai.debug", **canaries))

    for value in canaries.values():
        assert value.split(" ")[-1] not in line


def test_secret_shaped_values_are_scrubbed_anywhere() -> None:
    raw = (
        "call https://svc:canary-pass@laravel.internal/x with Bearer canary-tok "
        "lyc_pk_0123.canarysecret base64:QUJDREVGR0hJSktMTU5PUA=="
    )
    cleaned = scrub(raw)

    for canary in ("canary-pass", "canary-tok", "canarysecret", "QUJDREVGR0hJSktMTU5PUA"):
        assert canary not in cleaned
    assert sanitize({"nested": {"api_key": "x-canary"}}) == {"nested": {"api_key": "[redacted]"}}
    assert sanitize({"footprint": "ok", "input_tokens": 3}) == {
        "footprint": "ok",
        "input_tokens": 3,
    }


def test_an_exception_contributes_its_class_and_frames_never_its_message() -> None:
    try:
        raise RuntimeError("canary-exception-message")
    except RuntimeError:
        import sys

        line = _line(
            _record("laravel_audit.write_through_failed", logging.WARNING, exc_info=sys.exc_info())
        )

    assert line["exception_class"] == "builtins.RuntimeError"
    assert line["ctx"]["exception"]["trace"]
    assert "canary-exception-message" not in json.dumps(line)


def test_uvicorn_access_lines_keep_method_path_status_only() -> None:
    line = _line(
        _record(
            '%s - "%s %s HTTP/%s" %d',
            name="uvicorn.access",
            args=("10.0.0.9:5555", "GET", "/health/ready?x=canary-query", "1.1", 200),
        )
    )

    assert line["event_code"] == "http.access"
    assert line["ctx"] == {"method": "GET", "path": "/health/ready", "status": 200}
    assert "10.0.0.9" not in json.dumps(line)
    assert "canary-query" not in json.dumps(line)


def test_configure_logging_emits_info_as_json_through_one_handler() -> None:
    stream = io.StringIO()
    configure_logging("production", "json")
    root = logging.getLogger()
    ours = [h for h in root.handlers if getattr(h, "_lycenza", False)]
    assert len(ours) == 1
    ours[0].setStream(stream)  # type: ignore[attr-defined]

    logging.getLogger("app.main").info(
        "ai.complete", extra={"school_id": "s-1", "prompt": "canary-p"}
    )

    written = stream.getvalue().strip()
    assert root.level == logging.INFO
    assert json.loads(written)["event_code"] == "ai.complete"
    assert "canary-p" not in written
    assert logging.getLogger("uvicorn.access").propagate is True
    assert logging.getLogger("uvicorn.access").handlers == []
