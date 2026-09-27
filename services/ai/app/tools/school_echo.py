import httpx

from app.core.config import settings
from app.core.security import encode_body, laravel_headers
from app.core.service_auth import ServiceKeyConfigError
from app.tools.registry import ToolExecutionError

REQUIRED_CAPABILITY = "school.settings.view"


async def handle(payload: dict) -> dict:
    """Relays to Laravel's AI-tool-contract endpoint, forwarding the
    context_token unchanged. This handler never inspects or trusts
    school_id/actor claims itself -- Laravel is the only party that can
    verify the token's signature (ADR 0023) and derive the real
    School/actor from it.
    """
    context_token = payload.get("context_token")
    if not context_token:
        raise ToolExecutionError("school-echo tool requires a context_token in its payload.")

    request_body = encode_body({"context_token": context_token})
    try:
        headers = laravel_headers("/tools/school-echo", request_body)
    except ServiceKeyConfigError as exc:
        raise ToolExecutionError("Service signing key unavailable.") from exc
    async with httpx.AsyncClient(base_url=settings.erp_contract_base_url, timeout=5.0) as client:
        response = await client.post("/tools/school-echo", content=request_body, headers=headers)

    # Gap G3: the status code only -- a response body never reaches the
    # error message, the 502 detail or a log line.
    if response.status_code != 200:
        raise ToolExecutionError(f"ERP tool contract returned {response.status_code}.")

    body = response.json()
    return body.get("result", {})
