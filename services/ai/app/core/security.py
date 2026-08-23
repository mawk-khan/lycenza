from fastapi import Header, HTTPException, status

from app.core.config import settings


async def require_service_token(x_service_token: str | None = Header(default=None)) -> None:
    """Verifies the caller is the Laravel platform (or another trusted
    internal caller), not a public client. This is service-to-service
    auth only — it is not a substitute for per-tenant/per-user
    authorization, which is enforced by capability checks in
    app.tools.registry. See docs/ai/AI-SECURITY.md.
    """
    if x_service_token != settings.service_token:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid service token"
        )
