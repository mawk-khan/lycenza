import logging
import time

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from app.agents.registry import AgentDefinition, agent_registry
from app.audit import laravel_audit
from app.audit.ledger import AuditEntry, audit_ledger
from app.core.config import settings
from app.core.health import router as health_router
from app.core.logging import configure_logging
from app.core.security import require_service_token
from app.core.startup import assert_safe_configuration
from app.core.trace import TraceContext
from app.gateway.completion_auth import CompletionAuthorizationError, authorize_completion
from app.gateway.router import ProviderNotAllowedError, UnknownProviderError, model_router
from app.providers.base import CompletionRequest
from app.tools import school_echo
from app.tools.registry import (
    ToolAuthorizationError,
    ToolDefinition,
    ToolExecutionError,
    tool_registry,
)

logger = logging.getLogger(__name__)

# Phase 0O.5A (ADR 0051 §5, §8): structured JSON on stderr, INFO and above,
# uvicorn's own loggers included; G3 redaction as the backstop.
configure_logging(settings.environment, settings.log_format.strip().lower())

# Gap G4: refuse to start if an external provider is registered while the
# real-provider switch is off (the router also refuses at registration and
# selection time).
model_router.assert_fail_closed()

# Phase 0O.1: refuse to start without a service token, or with the public
# development token outside local/testing (app.core.startup).
assert_safe_configuration(settings)

app = FastAPI(title="School OS AI Gateway", version="0.0.1-phase-0b")
app.include_router(health_router)


@app.exception_handler(RequestValidationError)
async def handle_validation_error(_: Request, exc: RequestValidationError) -> JSONResponse:
    """Gap G3: FastAPI's default 422 echoes the offending input, which could
    be a prompt or a context token. Report only where and what kind of
    problem -- never the value."""
    return JSONResponse(
        status_code=422,
        content={
            "detail": [
                {"loc": list(error.get("loc", ())), "type": error.get("type")}
                for error in exc.errors()
            ]
        },
    )


@app.exception_handler(ToolAuthorizationError)
async def handle_tool_authorization_error(_: Request, exc: ToolAuthorizationError) -> JSONResponse:
    return JSONResponse(status_code=403, content={"detail": str(exc)})


@app.exception_handler(ToolExecutionError)
async def handle_tool_execution_error(_: Request, exc: ToolExecutionError) -> JSONResponse:
    return JSONResponse(status_code=502, content={"detail": str(exc)})


# Phase 0B primitive: proves the full Agent -> Capability -> Tool ->
# Authorization -> Domain service -> Audit chain (ADR 0014, ADR 0023)
# end to end. Not a real agent -- no customer-facing AI feature exists
# yet (docs/ai/AI-SECURITY.md).
tool_registry.register(
    ToolDefinition(
        name="school.echo",
        required_capability="school.echo.invoke",
        description="Relays to Laravel's read-only school-echo AI tool contract (Phase 0B proof).",
        handler=school_echo.handle,
    )
)

agent_registry.register(
    AgentDefinition(
        name="phase0b-proof-agent",
        granted_capabilities=frozenset({"school.echo.invoke"}),
        # Gap G1 proof path: reuses the capability the proof tool already
        # relies on -- no new, future production permission is invented.
        completion_capability="school.settings.view",
    )
)


class CompletionApiRequest(BaseModel):
    agent: str
    context_token: str
    prompt: str
    provider: str | None = None
    # Optional and never trusted: if present it must match the School in
    # the verified context token, which is the only authoritative source.
    school_id: str | None = None


class CompletionApiResponse(BaseModel):
    text: str
    provider: str
    model: str


def _refuse(status_code: int, code: str) -> HTTPException:
    return HTTPException(status_code=status_code, detail=code)


@app.post(
    "/v1/complete",
    response_model=CompletionApiResponse,
    dependencies=[Depends(require_service_token)],
)
async def complete(
    request: CompletionApiRequest, traceparent: str | None = Header(default=None)
) -> CompletionApiResponse:
    """A model completion, fail-closed (gaps G1-G4,
    docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md):

    1. the agent must declare a completion capability (else 403);
    2. the provider must be selectable -- an external provider is refused
       while the real-provider switch is off;
    3. Laravel verifies the signed context token and that the actor still
       holds the agent's capability, and returns the only authoritative
       School/actor -- all BEFORE the provider is called;
    4. the provider runs (only the offline NullProvider exists);
    5. the call is audited durably in Laravel (identifiers and numbers
       only). If that audit cannot be written, the output is withheld
       (503): no model output leaves without its audit record.

    Errors carry stable codes only -- never a prompt, output or body.
    """
    try:
        agent = agent_registry.get(request.agent)
    except KeyError:
        raise _refuse(403, "agent_not_allowed") from None
    if agent.completion_capability is None:
        raise _refuse(403, "agent_not_allowed")

    try:
        provider = model_router.resolve(request.provider)
    except (ProviderNotAllowedError, UnknownProviderError):
        raise _refuse(403, "provider_not_allowed") from None

    try:
        authorization = await authorize_completion(
            context_token=request.context_token,
            capability=agent.completion_capability,
            school_id=request.school_id,
        )
    except CompletionAuthorizationError as exc:
        raise _refuse(exc.status_code, exc.code) from None

    if request.school_id is not None and request.school_id != authorization.school_id:
        raise _refuse(422, "context_mismatch")

    trace = TraceContext.from_header(traceparent)
    started = time.monotonic()
    try:
        result = await provider.complete(CompletionRequest(prompt=request.prompt))
        outcome, provider_error = "succeeded", None
    except Exception as exc:  # noqa: BLE001 -- any provider failure is normalized
        result, outcome, provider_error = None, "provider_error", type(exc).__name__
    latency_ms = int((time.monotonic() - started) * 1000)

    audit_ledger.record(
        AuditEntry(
            school_id=authorization.school_id,
            agent=request.agent,
            tool=None,
            action="model.complete",
        )
    )
    durable = await laravel_audit.write_through(
        school_id=authorization.school_id,
        agent=request.agent,
        tool=None,
        action="model.complete",
        context_token=request.context_token,
        trace=trace,
        provider=provider.name,
        model=result.model if result is not None else None,
        outcome=outcome,
        latency_ms=latency_ms,
        input_tokens=result.input_tokens if result is not None else None,
        output_tokens=result.output_tokens if result is not None else None,
    )

    logger.info(
        "ai.complete",
        extra={
            # Correlation only (verified token claim, shared trace id).
            "request_id": authorization.request_id,
            "trace_id": trace.trace_id,
            "school_id": authorization.school_id,
            "agent": request.agent,
            "provider": provider.name,
            "outcome": outcome,
            "latency_ms": latency_ms,
            "audited": durable is not None,
            "provider_error": provider_error,
        },
    )

    if result is None:
        raise _refuse(502, "provider_error")
    if durable is None:
        raise _refuse(503, "audit_unavailable")

    return CompletionApiResponse(text=result.text, provider=result.provider, model=result.model)


class ToolInvokeApiRequest(BaseModel):
    school_id: str
    agent: str
    tool: str
    context_token: str
    payload: dict = {}


class ToolInvokeApiResponse(BaseModel):
    result: dict


@app.post(
    "/v1/tools/invoke",
    response_model=ToolInvokeApiResponse,
    dependencies=[Depends(require_service_token)],
)
async def invoke_tool(
    request: ToolInvokeApiRequest, traceparent: str | None = Header(default=None)
) -> ToolInvokeApiResponse:
    """Phase 0B primitive: an agent's OWN granted-capability set (this
    service's local, static registry -- see app/agents/registry.py) is
    checked first; the tool handler then relays context_token to
    Laravel unchanged, where a SECOND, independent, cryptographically
    verified authorization check happens against the real
    actor/School/capability (ADR 0023). Neither check alone is
    sufficient; both must pass.
    """
    agent = agent_registry.get(request.agent)

    result = await tool_registry.invoke(
        request.tool,
        granted_capabilities=set(agent.granted_capabilities),
        payload={
            "school_id": request.school_id,
            "context_token": request.context_token,
            **request.payload,
        },
    )

    audit_ledger.record(
        AuditEntry(
            school_id=request.school_id,
            agent=request.agent,
            tool=request.tool,
            action="tool.invoke",
        )
    )

    # Laravel is the authoritative, durable audit store (section 58) --
    # the in-process ledger above is a local diagnostic only. Reuses
    # the same context_token Laravel already verified for the tool call
    # itself; no separate credential is minted for this write-back.
    # Section 43: the SAME trace this request arrived under propagates
    # to the write-back call (a fresh child span, minted inside
    # write_through itself) -- closing the HTTP -> Laravel -> FastAPI ->
    # Laravel-audit-callback trace lineage.
    await laravel_audit.write_through(
        school_id=request.school_id,
        agent=request.agent,
        tool=request.tool,
        action="tool.invoke",
        context_token=request.context_token,
        trace=TraceContext.from_header(traceparent),
    )

    return ToolInvokeApiResponse(result=result)
