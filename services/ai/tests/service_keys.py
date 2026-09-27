"""Test-only Ed25519 service keys, generated at runtime (ADR 0053 section 11):
no committed or production-capable private key is involved in any test."""

import base64
import json
from datetime import UTC, date, datetime
from typing import Any

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import (
    Encoding,
    NoEncryption,
    PrivateFormat,
    PublicFormat,
)

from app.core.service_auth import (
    AUDIENCE_AI_GATEWAY,
    PLATFORM,
    ServiceSigner,
    parse_signing_key,
)

TODAY = datetime.now(UTC).date().isoformat()


def _b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


class TestKey:
    def __init__(self, kid: str, created: str = TODAY) -> None:
        self.kid, self.created = kid, created
        self._key = Ed25519PrivateKey.generate()
        self.x = _b64(self._key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw))
        self.d = _b64(self._key.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption()))

    def private_jwk(self, **overrides: Any) -> str:
        jwk = {
            "kty": "OKP",
            "crv": "Ed25519",
            "kid": self.kid,
            "x": self.x,
            "d": self.d,
            "created": self.created,
        }
        jwk.update(overrides)
        return json.dumps(jwk)

    def public(self, **overrides: Any) -> dict[str, Any]:
        jwk: dict[str, Any] = {
            "kty": "OKP",
            "crv": "Ed25519",
            "kid": self.kid,
            "x": self.x,
            "created": self.created,
        }
        jwk.update(overrides)
        return jwk


def ring(*keys: dict[str, Any]) -> str:
    return json.dumps(list(keys))


def at(text: str) -> datetime:
    return datetime.fromisoformat(text).replace(tzinfo=UTC)


PLATFORM_KEY = TestKey("test-platform-1")
GATEWAY_KEY = TestKey("test-ai-gateway-1")


def platform_signer(key: TestKey = PLATFORM_KEY, clock: Any = None, **kwargs: Any) -> ServiceSigner:
    return ServiceSigner(
        parse_signing_key(key.private_jwk(), clock() if clock else None),
        issuer=kwargs.pop("issuer", PLATFORM),
        audience=kwargs.pop("audience", AUDIENCE_AI_GATEWAY),
        clock=clock or (lambda: datetime.now(UTC)),
        **kwargs,
    )


def signed(
    path: str, payload: dict[str, Any] | None = None, signer: ServiceSigner | None = None
) -> dict[str, Any]:
    """Keyword arguments for TestClient.post: exact body bytes + a fresh assertion."""
    body = json.dumps(payload if payload is not None else {}, separators=(",", ":")).encode()
    signer = signer or platform_signer()
    return {
        "content": body,
        "headers": {
            "Content-Type": "application/json",
            "Authorization": signer.authorization("POST", "http://gateway.test" + path, body),
        },
    }


__all__ = [
    "GATEWAY_KEY",
    "PLATFORM_KEY",
    "TestKey",
    "at",
    "date",
    "platform_signer",
    "ring",
    "signed",
]
