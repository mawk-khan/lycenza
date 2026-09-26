"""Phase 0O.5A (ADR 0051 §5, §8): structured, redacted Gateway logs.

One JSON object per line on stderr, with the same fixed top-level field
catalog as apps/platform's StructuredJsonFormatter (``service`` is
``ai-gateway``, ``process_role`` is ``gateway``). INFO operational events
are emitted (the root logger defaults to WARNING without this) and the
``extra=`` fields call sites pass are actually serialized.

G3 stays strict (docs/ai/AI-SECURITY.md): prompts, outputs, request or
response bodies, credentials, service tokens and context tokens are never
logged. The call sites pass only identifiers and numbers; this module is
the backstop -- sensitive keys are redacted by word segment and
secret-shaped values are scrubbed. Exceptions contribute their class and
argument-free frames, never their message. Correlation with Laravel uses
the verified context token's ``request_id`` and the shared ``traceparent``
trace id; no spans are exported (no tracing in v1).
"""

from __future__ import annotations

import json
import logging
import re
import sys
import traceback
from datetime import UTC, datetime
from typing import Any

SERVICE = "ai-gateway"
PROCESS_ROLE = "gateway"
REDACTED = "[redacted]"

FIELDS = (
    "time",
    "level",
    "service",
    "process_role",
    "environment",
    "event_code",
    "message",
    "request_id",
    "correlation_id",
    "trace_id",
    "school_id",
    "actor_user_id",
    "outcome",
    "error_code",
    "exception_class",
    "ctx",
)
_PROMOTED = (
    "request_id",
    "correlation_id",
    "trace_id",
    "school_id",
    "actor_user_id",
    "outcome",
    "error_code",
)

_SENSITIVE_WORDS = (
    "password",
    "secret",
    "authorization",
    "cookie",
    "credential",
    "credentials",
    "private_key",
    "api_key",
    "apikey",
    "access_key",
    "service_token",
    "context_token",
    "signing_key",
    "prompt",
    "completion",
    "output",
    "text",
    "body",
    "content",
    "payload",
    "token",
)

_VALUE_PATTERNS = (
    (re.compile(r"\bBearer\s+[A-Za-z0-9._~+/|=-]+", re.IGNORECASE), "Bearer " + REDACTED),
    (re.compile(r"\blyc_(pat|pk)_[A-Za-z0-9._|-]+"), r"lyc_\1_" + REDACTED),
    (re.compile(r"\bbase64:[A-Za-z0-9+/=]{16,}"), "base64:" + REDACTED),
    (
        re.compile(r"\b([a-z][a-z0-9+.-]*://)[^\s/:@]+:[^\s/@]+@", re.IGNORECASE),
        r"\1" + REDACTED + "@",
    ),
    (re.compile(r"dev-local-only-(?:token|context-signing-key-change-me)"), REDACTED),
)

_EVENT_CODE = re.compile(r"^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$")
_STANDARD_ATTRS = frozenset(vars(logging.LogRecord("x", 0, "x", 0, "x", None, None))) | {
    "message",
    "asctime",
    "taskName",
}


def _segments(key: str) -> str:
    snake = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", key)
    return "_" + re.sub(r"[^A-Za-z0-9]+", "_", snake).lower() + "_"


def is_sensitive(key: str, value: Any) -> bool:
    """Numbers and booleans are never credentials here (``input_tokens``,
    ``output_tokens`` and ``latency_ms`` stay readable)."""
    if isinstance(value, bool | int | float):
        return False
    normalized = _segments(key)
    return any(f"_{word}_" in normalized for word in _SENSITIVE_WORDS)


def scrub(value: str) -> str:
    for pattern, replacement in _VALUE_PATTERNS:
        value = pattern.sub(replacement, value)
    return value


def sanitize(value: Any, key: str = "") -> Any:
    if key and value is not None and is_sensitive(key, value):
        return REDACTED
    if isinstance(value, dict):
        return {str(k): sanitize(v, str(k)) for k, v in value.items()}
    if isinstance(value, list | tuple):
        return [sanitize(v) for v in value]
    if isinstance(value, str):
        return scrub(value)
    if value is None or isinstance(value, bool | int | float):
        return value
    return scrub(str(value))


class JsonFormatter(logging.Formatter):
    def __init__(self, environment: str) -> None:
        super().__init__()
        self.environment = environment

    def format(self, record: logging.LogRecord) -> str:
        message = scrub(record.getMessage()) if record.name != "uvicorn.access" else ""
        line: dict[str, Any] = {
            "time": datetime.fromtimestamp(record.created, UTC).strftime("%Y-%m-%dT%H:%M:%S.")
            + f"{int(record.msecs):03d}Z",
            "level": record.levelname.lower(),
            "service": SERVICE,
            "process_role": PROCESS_ROLE,
            "environment": self.environment,
        }

        ctx: dict[str, Any] = {}
        for key, value in vars(record).items():
            if key in _STANDARD_ATTRS or key.startswith("_"):
                continue
            clean = sanitize(value, key)
            if key in _PROMOTED and isinstance(clean, str | int | float) and clean != "":
                line[key] = clean
            elif clean is not None:
                ctx[key] = clean

        if (
            record.name == "uvicorn.access"
            and isinstance(record.args, tuple)
            and len(record.args) == 5
        ):
            _client, method, path, _version, status = record.args
            line["event_code"] = "http.access"
            ctx.update(
                {"method": str(method), "path": str(path).split("?", 1)[0], "status": status}
            )
        elif _EVENT_CODE.match(message):
            line["event_code"] = message
        elif message:
            line["message"] = message[:1000]

        if record.exc_info and record.exc_info[1] is not None:
            exc = record.exc_info[1]
            line["exception_class"] = type(exc).__module__ + "." + type(exc).__qualname__
            ctx["exception"] = {
                "trace": [
                    f"{frame.name} {frame.filename.rsplit('/', 1)[-1]}:{frame.lineno}"
                    for frame in traceback.extract_tb(exc.__traceback__)[-20:]
                ]
            }

        if ctx:
            line["ctx"] = ctx

        ordered = {field: line[field] for field in FIELDS if field in line}
        return json.dumps(ordered, separators=(",", ":"), default=str)


def configure_logging(
    environment: str, log_format: str = "json", level: int = logging.INFO
) -> None:
    """Route every Gateway and uvicorn logger through one stderr handler."""
    handler = logging.StreamHandler(sys.stderr)
    if log_format == "json":
        handler.setFormatter(JsonFormatter(environment))
    else:
        handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(name)s %(message)s"))

    root = logging.getLogger()
    root.handlers = [h for h in root.handlers if not getattr(h, "_lycenza", False)]
    handler._lycenza = True  # type: ignore[attr-defined]
    root.addHandler(handler)
    root.setLevel(level)

    for name in ("uvicorn", "uvicorn.error", "uvicorn.access"):
        uvicorn_logger = logging.getLogger(name)
        uvicorn_logger.handlers.clear()
        uvicorn_logger.propagate = True
