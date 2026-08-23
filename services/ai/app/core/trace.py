"""Phase 0C.4 section 41-43: a W3C Trace Context-compatible tracing
primitive mirroring apps/platform's App\\Support\\Observability\\
TraceContext exactly -- same format, same "reuse trace-id, always mint
a fresh span-id locally, never trust an inbound span-id as this
service's own" semantics. No OpenTelemetry SDK dependency on either
side (ADR 0015's documented allowance) -- both services implement the
same small, tested, spec-compatible primitive instead.

Diagnostic only: trace-id/span-id carry no School/actor identity and
are never consulted for authorization anywhere in this service.
"""

from __future__ import annotations

import re
import secrets
from dataclasses import dataclass

_TRACEPARENT_RE = re.compile(
    r"^(?P<version>[0-9a-f]{2})-(?P<trace_id>[0-9a-f]{32})-(?P<span_id>[0-9a-f]{16})-(?P<flags>[0-9a-f]{2})$"
)


@dataclass(frozen=True)
class TraceContext:
    trace_id: str
    span_id: str
    sampled: bool

    @staticmethod
    def start() -> TraceContext:
        return TraceContext(
            trace_id=secrets.token_hex(16), span_id=secrets.token_hex(8), sampled=True
        )

    @staticmethod
    def from_header(traceparent: str | None) -> TraceContext:
        if traceparent:
            match = _TRACEPARENT_RE.match(traceparent)
            if match and match.group("version") != "ff" and match.group("trace_id") != "0" * 32:
                flags = int(match.group("flags"), 16)
                return TraceContext(
                    trace_id=match.group("trace_id"),
                    span_id=secrets.token_hex(8),
                    sampled=(flags & 0x01) == 1,
                )
        return TraceContext.start()

    def child_span(self) -> TraceContext:
        return TraceContext(
            trace_id=self.trace_id, span_id=secrets.token_hex(8), sampled=self.sampled
        )

    def to_header(self) -> str:
        flags = "01" if self.sampled else "00"
        return f"00-{self.trace_id}-{self.span_id}-{flags}"
