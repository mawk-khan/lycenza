import httpx

from app.core.config import settings
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

    async with httpx.AsyncClient(base_url=settings.erp_contract_base_url, timeout=5.0) as client:
        response = await client.post(
            "/tools/school-echo",
            json={"context_token": context_token},
            headers={"Authorization": f"Bearer {settings.service_token}"},
        )

    if response.status_code != 200:
        raise ToolExecutionError(
            f"ERP tool contract returned {response.status_code}: {response.text}"
        )

    body = response.json()
    return body.get("result", {})
