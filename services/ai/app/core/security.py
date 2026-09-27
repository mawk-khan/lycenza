"""ADR 0053 (Phase 0O.7A): service-to-service authentication for the Gateway.

Inbound (Laravel -> Gateway): ``ServiceAuthMiddleware`` verifies, before any
routing or body parsing, a per-request `platform` Ed25519 assertion (audience
``lycenza-ai-gateway``) and the route's closed service scope for every route
in ``service_auth.ROUTE_SCOPES``. This is service authentication ONLY -- it never establishes a
School, actor or capability; that is the separate ADR 0023 context token,
which only Laravel verifies (app.gateway.completion_auth, app.tools).

Outbound (Gateway -> Laravel): ``laravel_headers`` mints one fresh
`ai-gateway` assertion (audience ``lycenza-platform-internal-ai``) per request.

There is no fallback: no shared token, no X-Service-Token, no Bearer.
Keys are read once at process start; changing them is a restart (ADR 0053
section 8.6).
"""

import json
import logging
from typing import Any

from starlette.responses import JSONResponse
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app.core.config import settings
from app.core.service_auth import (
    AI_GATEWAY,
    AUDIENCE_AI_GATEWAY,
    AUDIENCE_PLATFORM_INTERNAL_AI,
    PLATFORM,
    ROUTE_SCOPES,
    SERVICE_SCOPES,
    ReplayCache,
    ServiceAuthError,
    ServiceNotAuthorizedError,
    ServiceSigner,
    ServiceVerifier,
    parse_signing_key,
    parse_verification_ring,
)
from app.core.startup import is_local_environment

logger = logging.getLogger(__name__)

INBOUND = "platform_to_gateway"
OUTBOUND = "gateway_to_platform"


def _build() -> tuple[ServiceSigner, ServiceVerifier]:
    enforce_key_age = not is_local_environment(settings)
    signer = ServiceSigner(
        parse_signing_key(settings.service_signing_key),
        issuer=AI_GATEWAY,
        audience=AUDIENCE_PLATFORM_INTERNAL_AI,
        enforce_key_age=enforce_key_age,
    )
    verifier = ServiceVerifier(
        parse_verification_ring(settings.platform_verification_keys),
        caller=PLATFORM,
        audience=AUDIENCE_AI_GATEWAY,
        route_scopes=ROUTE_SCOPES,
        service_scopes=SERVICE_SCOPES,
        replay=ReplayCache(),
        enforce_key_age=enforce_key_age,
    )
    return signer, verifier


class _ServiceAuth:
    """Process-wide signer and verifier, built lazily after the startup check."""

    def __init__(self) -> None:
        self._built: tuple[ServiceSigner, ServiceVerifier] | None = None

    @property
    def signer(self) -> ServiceSigner:
        return self._get()[0]

    @property
    def verifier(self) -> ServiceVerifier:
        return self._get()[1]

    def _get(self) -> tuple[ServiceSigner, ServiceVerifier]:
        if self._built is None:
            self._built = _build()
        return self._built

    def replace(self, signer: ServiceSigner, verifier: ServiceVerifier) -> None:
        """Tests and a process restart only: keys are never mutated at runtime."""
        self._built = (signer, verifier)

    def reset(self) -> None:
        self._built = None


service_auth = _ServiceAuth()


class ServiceAuthMiddleware:
    """Pure ASGI: authenticates every catalogued business route BEFORE routing,
    body parsing or validation (ADR 0053 section 5.4). The exact body bytes are
    buffered, verified against the assertion's digest, then replayed unchanged
    to the application. Routes outside the closed catalog (health) pass through;
    a path that merely decodes to a catalogued one fails the raw-path binding."""

    def __init__(self, app: ASGIApp) -> None:
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http" or (scope["method"], scope["path"]) not in ROUTE_SCOPES:
            await self.app(scope, receive, send)
            return

        chunks: list[bytes] = []
        more = True
        while more:
            message = await receive()
            if message["type"] == "http.disconnect":
                return
            chunks.append(message.get("body", b""))
            more = message.get("more_body", False)
        body = b"".join(chunks)

        route = scope["path"]
        headers = {k.decode("latin-1").lower(): v.decode("latin-1") for k, v in scope["headers"]}
        try:
            verified = service_auth.verifier.verify(
                headers.get("authorization"),
                method=scope["method"],
                raw_path=scope.get("raw_path", b"").decode("latin-1"),
                query=scope.get("query_string", b"").decode("latin-1"),
                body=body,
            )
        except ServiceAuthError as exc:
            _event("failed", exc.code, route, kid=exc.kid)
            await _refuse(401, "service_authentication_failed")(scope, receive, send)
            return
        except ServiceNotAuthorizedError as exc:
            _event("failed", "service_not_authorized", route, service=exc.service, kid=exc.kid)
            await _refuse(403, "service_not_authorized")(scope, receive, send)
            return
        _event("succeeded", "success", route, service=verified.service, kid=verified.kid)
        scope.setdefault("state", {})["service"] = verified

        sent = False

        async def replay() -> Message:
            nonlocal sent
            if not sent:
                sent = True
                return {"type": "http.request", "body": body, "more_body": False}
            return await receive()

        await self.app(scope, replay, send)


def _refuse(status: int, code: str) -> JSONResponse:
    """ADR 0053 section 5.6: one uniform body per status; the reason code goes
    to the log only (never a key or claim oracle)."""
    return JSONResponse(status_code=status, content={"error": {"code": code}})


def laravel_headers(path: str, body: bytes, extra: dict[str, str] | None = None) -> dict[str, str]:
    """Headers for one Gateway -> Laravel call: a fresh assertion bound to
    POST, the exact path Laravel will see, and these exact body bytes."""
    url = settings.erp_contract_base_url.rstrip("/") + path
    return {
        "Content-Type": "application/json",
        "Authorization": service_auth.signer.authorization("POST", url, body),
        **(extra or {}),
    }


def encode_body(payload: dict[str, Any]) -> bytes:
    """Serialize once; these exact bytes are both signed and sent."""
    return json.dumps(payload, separators=(",", ":")).encode()


def _event(
    outcome: str, reason: str, route: str, *, service: str | None = None, kid: str | None = None
) -> None:
    fields = {
        "peer_service": service or "unknown",
        "direction": INBOUND,
        "outcome": reason,
        "route": route,
    }
    if kid is not None:
        fields["kid"] = kid
    if outcome == "succeeded":
        logger.debug("service_auth.succeeded", extra=fields)
    else:
        logger.warning("service_auth.failed", extra=fields)
