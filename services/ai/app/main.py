from fastapi import Depends, FastAPI, Header, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from app.agents.registry import AgentDefinition, agent_registry
from app.audit import laravel_audit
from app.audit.ledger import AuditEntry, audit_ledger
from app.core.health import router as health_router
from app.core.security import require_service_token
from app.core.trace import TraceContext
from app.gateway.router import model_router
from app.providers.base import CompletionRequest
from app.tools import school_echo
from app.tools.registry import (
    ToolAuthorizationError,
    ToolDefinition,
    ToolExecutionError,
    tool_registry,
)

app = FastAPI(title="School OS AI Gateway", version="0.0.1-phase-0b")
app.include_router(health_router)


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
    )
)


class CompletionApiRequest(BaseModel):
    school_id: str
    agent: str
    prompt: str
    provider: str | None = None


class CompletionApiResponse(BaseModel):
    text: str
    provider: str
    model: str


@app.post(
    "/v1/complete",
    response_model=CompletionApiResponse,
    dependencies=[Depends(require_service_token)],
)
async def complete(request: CompletionApiRequest) -> CompletionApiResponse:
    """Phase 0A primitive proving the AI Gateway boundary end to end:
    an authenticated internal caller -> model router -> provider
    interface -> audit ledger. Only the offline NullProvider is wired
    up; no real model provider call happens in this checkpoint.
    """
    provider = model_router.resolve(request.provider)
    result = await provider.complete(CompletionRequest(prompt=request.prompt))

    audit_ledger.record(
        AuditEntry(
            school_id=request.school_id,
            agent=request.agent,
            tool=None,
            action="model.complete",
        )
    )

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
