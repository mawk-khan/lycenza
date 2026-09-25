import hmac

from fastapi import Header, HTTPException, status

from app.core.config import settings


async def require_service_token(x_service_token: str | None = Header(default=None)) -> None:
    """Verifies the caller is the Laravel platform (or another trusted
    internal caller), not a public client. This is service-to-service
    auth only — it is not a substitute for per-tenant/per-user
    authorization, which is enforced by capability checks in
    app.tools.registry. See docs/ai/AI-SECURITY.md.
    """
    expected = settings.service_token
    # Phase 0O.1: constant-time comparison, and an unset expected token
    # never matches (startup already refuses to run without one).
    if (
        not expected.strip()
        or x_service_token is None
        or not hmac.compare_digest(x_service_token.encode(), expected.encode())
    ):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid service token"
        )
