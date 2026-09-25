"""Phase 0C.4 sections 5/11: liveness vs readiness are distinct here
exactly like apps/platform's (see App\\Http\\Controllers\\Api\\
Internal\\HealthController) -- liveness never fails on a dependency,
readiness checks only what THIS service genuinely needs to operate.

This service holds no database/cache connection of its own (Phase 0A/
0B/0C never gave it one -- ADR 0013/0014 forbid direct ERP DB access,
and the in-process AuditLedger is the only local state, which needs no
connection check). Readiness therefore does NOT call Laravel: a
Laravel outage does not make the AI Gateway process itself "not ready"
-- App\\Jobs's-equivalent-of "the write-back fails safely" behavior in
app.audit.laravel_audit already documents that Laravel reachability is
an optional-subsystem concern, not a hard dependency of this service's
own readiness (section 11: "do NOT call OpenAI/Anthropic/Gemini
during readiness" applies by the same reasoning to any other network
dependency this service does not strictly need to boot).
"""

from fastapi import APIRouter
from fastapi.responses import JSONResponse

from app.core.config import settings
from app.core.startup import configuration_violations

router = APIRouter(prefix="/health", tags=["health"])


@router.get("/live")
async def live() -> dict:
    """Is this process alive enough that restarting it is not
    immediately warranted? Cheap, no dependency checks, no
    infrastructure details in the response.
    """
    return {"status": "ok"}


@router.get("/ready", response_model=None)
async def ready() -> dict | JSONResponse:
    """Can this instance safely receive normal tool-invocation traffic?
    Checks only configuration this service itself needs -- never a
    live call to Laravel or a model provider (section 11).

    Phase 0O.1: not ready is HTTP 503 (a load balancer or orchestrator
    reads the status code, not the body), and the body never names the
    reason or a value.
    """
    if configuration_violations(settings):
        return JSONResponse(status_code=503, content={"status": "unhealthy"})

    return {"status": "ok"}
