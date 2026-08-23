import logging

import httpx

from app.core.config import settings
from app.core.trace import TraceContext

logger = logging.getLogger(__name__)


async def write_through(
    *,
    school_id: str,
    agent: str,
    tool: str | None,
    action: str,
    context_token: str,
    trace: TraceContext | None = None,
) -> dict | None:
    """Writes a durable audit entry into Laravel's authoritative
    SchoolAuditEvent store (Phase 0C section 58/69) -- resolves the
    Phase 0B AI-side audit debt: app.audit.ledger.AuditLedger alone is
    not durable and must never become an independent shadow database.

    Reuses the SAME signed context_token the caller already holds for
    the tool invocation itself (ADR 0023) -- this service never mints
    or inspects the token, only relays it unchanged, exactly like
    app.tools.school_echo does for the tool-invocation call.

    Bounded timeout, and deliberately fails SAFELY: if Laravel is
    unreachable or rejects the write, the AI Gateway action itself has
    already completed and must not be rolled back or fail the caller's
    request over an audit-plumbing problem. The failure is logged
    locally (and the in-process AuditLedger still records the action)
    so it is visible in this service's own logs, but it never surfaces
    as a 5xx to the agent/caller.
    """
    # Section 43: propagate a CHILD span of whatever trace this call
    # arrived under (e.g. the one Laravel started for the original
    # /v1/tools/invoke request) -- Laravel's own AssignTraceContext
    # middleware picks this up on the receiving end, closing the loop
    # (HTTP -> Laravel -> FastAPI -> Laravel audit callback, same
    # trace-id throughout).
    child = (trace or TraceContext.start()).child_span()

    try:
        async with httpx.AsyncClient(
            base_url=settings.erp_contract_base_url, timeout=5.0
        ) as client:
            response = await client.post(
                "/audit",
                json={
                    "context_token": context_token,
                    "school_id": school_id,
                    "agent": agent,
                    "tool": tool,
                    "action": action,
                },
                headers={
                    "Authorization": f"Bearer {settings.service_token}",
                    "traceparent": child.to_header(),
                },
            )
    except httpx.HTTPError:
        logger.warning("laravel_audit.write_through_failed", exc_info=True)
        return None

    if response.status_code != 200:
        logger.warning(
            "laravel_audit.write_through_rejected",
            extra={"status_code": response.status_code, "body": response.text},
        )
        return None

    return response.json().get("audit")
