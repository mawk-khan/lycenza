from dataclasses import dataclass

import httpx

from app.core.config import settings
from app.core.security import encode_body, laravel_headers
from app.core.service_auth import ServiceKeyConfigError


@dataclass(frozen=True)
class CompletionAuthorization:
    """The only authoritative School and actor for a completion, as
    verified by Laravel from the signed context token (ADR 0023)."""

    school_id: str
    actor_id: str
    request_id: str | None


class CompletionAuthorizationError(RuntimeError):
    """The completion was refused, or could not be authorized. Carries a
    stable code and HTTP status only -- never a response body."""

    def __init__(self, code: str, status_code: int) -> None:
        super().__init__(code)
        self.code = code
        self.status_code = status_code


_REFUSALS = {401: "context_invalid", 403: "capability_denied", 422: "context_mismatch"}


async def authorize_completion(
    *, context_token: str, capability: str, school_id: str | None
) -> CompletionAuthorization:
    """Gap G1: this service holds no signing key, so Laravel verifies the
    context token (signature, expiry, capability claim, optional School
    match, and the actor still holding the capability) BEFORE any model
    provider is called. Any refusal or failure raises; nothing is retried.
    """
    body = encode_body(
        {"context_token": context_token, "capability": capability, "school_id": school_id}
    )
    try:
        async with httpx.AsyncClient(
            base_url=settings.erp_contract_base_url, timeout=5.0
        ) as client:
            response = await client.post(
                "/completions/authorize",
                content=body,
                headers=laravel_headers("/completions/authorize", body),
            )
    except (httpx.HTTPError, ServiceKeyConfigError) as exc:
        # ServiceKeyConfigError: this service's signing key can no longer sign
        # (e.g. past its 90-day life) -- fail closed, never unsigned.
        raise CompletionAuthorizationError("authorization_unavailable", 503) from exc

    if response.status_code in _REFUSALS:
        raise CompletionAuthorizationError(_REFUSALS[response.status_code], response.status_code)
    if response.status_code != 200:
        raise CompletionAuthorizationError("authorization_unavailable", 503)

    data = response.json().get("authorization") or {}
    school = data.get("schoolId")
    actor = data.get("actorId")
    if not isinstance(school, str) or not isinstance(actor, str):
        raise CompletionAuthorizationError("authorization_unavailable", 503)

    return CompletionAuthorization(
        school_id=school, actor_id=actor, request_id=data.get("requestId")
    )
