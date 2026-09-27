"""ADR 0053 (Phase 0O.7A): per-request, request-bound Ed25519 service assertions.

Service authentication only -- "this request came from an authorized internal
service". It never carries or establishes a School, actor, capability or
elevation; that stays the separately signed ADR 0023 context token, which only
Laravel can verify.

A JWS compact serialization (RFC 7515, RFC 8037) with a fixed, closed shape:
- header exactly {alg: EdDSA, typ: lycenza-service+jwt, kid};
- payload exactly {ver, iss, sub, aud, iat, nbf, exp, jti, htm, htp, bsh}
  plus an optional rid.

The Ed25519 primitive is PyCA ``cryptography``; nothing here implements
cryptography itself. Lifetime, skew, overlap, algorithm and key age are
constants, never configuration.
"""

from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import json
import re
import secrets
import threading
from collections import OrderedDict
from collections.abc import Callable, Mapping
from dataclasses import dataclass
from datetime import UTC, date, datetime, timedelta
from typing import Any
from urllib.parse import urlsplit

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey,
    Ed25519PublicKey,
)
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

ALG = "EdDSA"
TYP = "lycenza-service+jwt"
SCHEME = "lycenza-service"
VERSION = 1
DEFAULT_LIFETIME_SECONDS = 60
MAX_LIFETIME_SECONDS = 120
CLOCK_SKEW_SECONDS = 30
MAX_ASSERTION_BYTES = 2048
MAX_KEY_AGE_DAYS = 90
KEY_AGE_WARNING_DAYS = 76
MAX_TRANSITION_SECONDS = 24 * 3600

PLATFORM = "platform"
AI_GATEWAY = "ai-gateway"
SERVICES = frozenset({PLATFORM, AI_GATEWAY})
AUDIENCE_AI_GATEWAY = "lycenza-ai-gateway"
AUDIENCE_PLATFORM_INTERNAL_AI = "lycenza-platform-internal-ai"

# The closed route catalog of THIS receiver (ADR 0053 section 6.1): no route is
# inferred from a prefix, and there is no wildcard.
ROUTE_SCOPES: Mapping[tuple[str, str], str] = {
    ("POST", "/v1/tools/invoke"): "gateway.tools.invoke",
    ("POST", "/v1/complete"): "gateway.complete",
}
SERVICE_SCOPES: Mapping[str, frozenset[str]] = {
    PLATFORM: frozenset({"gateway.tools.invoke", "gateway.complete"}),
}

# The committed development/test keys (services/ai/.env.example,
# apps/platform/.env.example, .ddev): refused outside local/testing by kid
# prefix AND public key.
DEVELOPMENT_KID_PREFIX = "dev-local-only-"
DEVELOPMENT_PUBLIC_KEYS = frozenset(
    {
        "kV3ZvDdDEsrXYdor998DAGfkdizSSsRSzKHycfGuEUQ",  # dev-local-only-platform-1
        "WibFMaiB1A6KTbuNEVn8oOm2Qg1pUy1upMSmLbIy1tE",  # dev-local-only-ai-gateway-1
    }
)

_KID = re.compile(r"[a-z0-9][a-z0-9._-]{0,63}")
_PATH = re.compile(r"/[A-Za-z0-9._/-]{0,255}")
_B64URL = re.compile(r"[A-Za-z0-9_-]*")
_RID = re.compile(r"[A-Za-z0-9._:-]{1,128}")
_DATE = re.compile(r"\d{4}-\d{2}-\d{2}")
_INSTANT = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z")
_HEADER_MEMBERS = frozenset({"alg", "typ", "kid"})
_REQUIRED_CLAIMS = frozenset(
    {"ver", "iss", "sub", "aud", "iat", "nbf", "exp", "jti", "htm", "htp", "bsh"}
)
_ALL_CLAIMS = _REQUIRED_CLAIMS | {"rid"}


class ServiceAuthError(Exception):
    """Authentication failed. ``code`` is a closed internal reason code; the
    HTTP response never carries it (ADR 0053 section 5.6)."""

    def __init__(self, code: str, kid: str | None = None) -> None:
        super().__init__(code)
        self.code = code
        self.kid = kid


class ServiceNotAuthorizedError(Exception):
    """A fully valid assertion from a service not authorized for this route (403)."""

    def __init__(self, service: str, kid: str) -> None:
        super().__init__("service_not_authorized")
        self.service = service
        self.kid = kid


class ServiceKeyConfigError(ValueError):
    """Key configuration is unusable. ``code`` is a bounded violation code."""

    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


def b64url_encode(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def b64url_decode(text: str) -> bytes:
    """Strict, canonical, unpadded base64url; anything else raises ValueError."""
    if not isinstance(text, str) or not _B64URL.fullmatch(text) or len(text) % 4 == 1:
        raise ValueError("base64url")
    try:
        data = base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))
    except (binascii.Error, ValueError) as exc:
        raise ValueError("base64url") from exc
    if b64url_encode(data) != text:
        raise ValueError("base64url")
    return data


def body_digest(body: bytes) -> str:
    return b64url_encode(hashlib.sha256(body).digest())


def _no_duplicate_members(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate member")
        result[key] = value
    return result


def _strict_json(raw: bytes | str) -> Any:
    return json.loads(raw, object_pairs_hook=_no_duplicate_members)


def _strict_json_object(raw: bytes | str) -> dict[str, Any]:
    value = _strict_json(raw)
    if not isinstance(value, dict):
        raise ValueError("not an object")
    return value


def _now() -> datetime:
    return datetime.now(UTC)


def _date(value: Any) -> date:
    if not isinstance(value, str) or not _DATE.fullmatch(value):
        raise ValueError("date")
    return date.fromisoformat(value)


def _key_material(jwk: dict[str, Any], member: str) -> bytes:
    raw = b64url_decode(jwk.get(member, ""))
    if len(raw) != 32:
        raise ValueError("key size")
    return raw


def _okp(jwk: dict[str, Any]) -> None:
    if jwk.get("kty") != "OKP" or jwk.get("crv") != "Ed25519":
        raise ValueError("key type")
    if not isinstance(jwk.get("kid"), str) or not _KID.fullmatch(jwk["kid"]):
        raise ValueError("kid")


@dataclass(frozen=True)
class SigningKey:
    kid: str
    created: date
    public: str
    private: Ed25519PrivateKey

    def age_days(self, now: datetime) -> int:
        return (now.date() - self.created).days

    def __repr__(self) -> str:  # never render key material
        return f"SigningKey(kid={self.kid!r}, created={self.created.isoformat()})"


@dataclass(frozen=True)
class VerificationKey:
    kid: str
    created: date
    public: str
    key: Ed25519PublicKey
    not_after: datetime | None

    def age_days(self, now: datetime) -> int:
        return (now.date() - self.created).days


def parse_signing_key(text: str, now: datetime | None = None) -> SigningKey:
    """One RFC 8037 OKP private JWK: {kty, crv, kid, x, d, created}, nothing else."""
    now = now or _now()
    try:
        jwk = _strict_json_object(text)
        if set(jwk) != {"kty", "crv", "kid", "x", "d", "created"}:
            raise ValueError("members")
        _okp(jwk)
        seed, public = _key_material(jwk, "d"), _key_material(jwk, "x")
        private = Ed25519PrivateKey.from_private_bytes(seed)
        derived = private.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
        if not hmac.compare_digest(derived, public):
            raise ValueError("x does not match d")
        created = _date(jwk["created"])
    except (ValueError, TypeError) as exc:
        raise ServiceKeyConfigError("signing_key_invalid") from exc
    if created > now.date():
        raise ServiceKeyConfigError("signing_key_invalid")
    return SigningKey(kid=jwk["kid"], created=created, public=jwk["x"], private=private)


def parse_verification_ring(text: str, now: datetime | None = None) -> tuple[VerificationKey, ...]:
    """1-2 public OKP JWKs; exactly one steady key (no not_after); at most one
    transitional key whose not_after is in the future and at most 24 h away."""
    now = now or _now()
    try:
        entries = _strict_json(text)
    except (ValueError, TypeError) as exc:
        raise ServiceKeyConfigError("verification_keys_invalid") from exc
    if not isinstance(entries, list) or not entries:
        raise ServiceKeyConfigError(
            "verification_keys_missing" if entries == [] else "verification_keys_invalid"
        )
    if len(entries) > 2:
        raise ServiceKeyConfigError("verification_keys_too_many")

    keys: list[VerificationKey] = []
    for jwk in entries:
        if not isinstance(jwk, dict):
            raise ServiceKeyConfigError("verification_keys_invalid")
        if "d" in jwk:
            raise ServiceKeyConfigError("verification_keys_private_material")
        try:
            if (
                not {"kty", "crv", "kid", "x", "created"}
                <= set(jwk)
                <= {"kty", "crv", "kid", "x", "created", "not_after"}
            ):
                raise ValueError("members")
            _okp(jwk)
            raw = _key_material(jwk, "x")
            created = _date(jwk["created"])
            not_after = None
            if "not_after" in jwk:
                if not isinstance(jwk["not_after"], str) or not _INSTANT.fullmatch(
                    jwk["not_after"]
                ):
                    raise ValueError("not_after")
                not_after = datetime.strptime(jwk["not_after"], "%Y-%m-%dT%H:%M:%SZ").replace(
                    tzinfo=UTC
                )
            key = Ed25519PublicKey.from_public_bytes(raw)
        except (ValueError, TypeError) as exc:
            raise ServiceKeyConfigError("verification_keys_invalid") from exc
        if created > now.date():
            raise ServiceKeyConfigError("verification_keys_invalid")
        if not_after is not None:
            if not_after <= now:
                raise ServiceKeyConfigError("verification_key_transition_expired")
            if not_after > now + timedelta(seconds=MAX_TRANSITION_SECONDS):
                raise ServiceKeyConfigError("verification_key_transition_too_long")
        keys.append(VerificationKey(jwk["kid"], created, jwk["x"], key, not_after))

    if len({k.kid for k in keys}) != len(keys):
        raise ServiceKeyConfigError("verification_keys_duplicate_kid")
    if sum(1 for k in keys if k.not_after is None) != 1:
        raise ServiceKeyConfigError("verification_keys_steady_key")
    return tuple(keys)


def is_development_key(kid: str, public: str) -> bool:
    return kid.startswith(DEVELOPMENT_KID_PREFIX) or public in DEVELOPMENT_PUBLIC_KEYS


def canonical_target(url: str) -> str:
    """The request-target path a receiver sees for ``url`` (ADR 0053 section 4.4)."""
    parts = urlsplit(url)
    if (
        parts.query
        or parts.fragment
        or not _PATH.fullmatch(parts.path)
        or not _canonical_path(parts.path)
    ):
        raise ServiceKeyConfigError("request_target_invalid")
    return parts.path


def _canonical_path(path: str) -> bool:
    if not _PATH.fullmatch(path) or (len(path) > 1 and path.endswith("/")):
        return False
    return all(segment not in ("", ".", "..") for segment in path[1:].split("/")) or path == "/"


class ServiceSigner:
    """Mints one fresh assertion per request (never cached or reused)."""

    def __init__(
        self,
        key: SigningKey,
        *,
        issuer: str,
        audience: str,
        enforce_key_age: bool = True,
        clock: Callable[[], datetime] = _now,
    ) -> None:
        if issuer not in SERVICES:
            raise ServiceKeyConfigError("unknown_service")
        self._key, self._issuer, self._audience = key, issuer, audience
        self._enforce_key_age, self._clock = enforce_key_age, clock

    @property
    def kid(self) -> str:
        return self._key.kid

    def assertion(self, method: str, url: str, body: bytes, request_id: str | None = None) -> str:
        now = self._clock()
        if self._enforce_key_age and self._key.age_days(now) > MAX_KEY_AGE_DAYS:
            raise ServiceKeyConfigError("signing_key_expired")
        iat = int(now.timestamp())
        claims: dict[str, Any] = {
            "ver": VERSION,
            "iss": self._issuer,
            "sub": self._issuer,
            "aud": self._audience,
            "iat": iat,
            "nbf": iat,
            "exp": iat + DEFAULT_LIFETIME_SECONDS,
            "jti": b64url_encode(secrets.token_bytes(16)),
            "htm": method.upper(),
            "htp": canonical_target(url),
            "bsh": body_digest(body),
        }
        if request_id is not None and _RID.fullmatch(request_id):
            claims["rid"] = request_id
        header = {"alg": ALG, "typ": TYP, "kid": self._key.kid}
        signing_input = (
            b64url_encode(json.dumps(header, separators=(",", ":")).encode())
            + "."
            + b64url_encode(json.dumps(claims, separators=(",", ":")).encode())
        )
        signature = self._key.private.sign(signing_input.encode("ascii"))
        return signing_input + "." + b64url_encode(signature)

    def authorization(
        self, method: str, url: str, body: bytes, request_id: str | None = None
    ) -> str:
        return "Lycenza-Service " + self.assertion(method, url, body, request_id)


@dataclass(frozen=True)
class VerifiedService:
    service: str
    kid: str
    scope: str


class ReplayCache:
    """Best-effort, per-process, bounded, expiry-aware seen-jti set (ADR 0053 section 5.5).

    It stops a replay to THIS process only. A replica or a restarted process
    has its own set, so the same assertion can still be accepted elsewhere
    within its validity window: the accepted v1 residual risk, to be
    reconsidered before Phase 0M. Stores only a digest of issuer+jti.
    """

    def __init__(self, max_entries: int = 10_000) -> None:
        self._entries: OrderedDict[str, float] = OrderedDict()
        self._lock = threading.Lock()
        self._max = max_entries

    def first_use(self, issuer: str, jti: str, expires_at: float, now: float) -> bool:
        key = hashlib.sha256(f"{issuer}:{jti}".encode()).hexdigest()
        with self._lock:
            for seen, expiry in list(self._entries.items()):
                if expiry > now:
                    break
                del self._entries[seen]
            if key in self._entries and self._entries[key] > now:
                return False
            self._entries[key] = expires_at
            self._entries.move_to_end(key)
            while len(self._entries) > self._max:
                self._entries.popitem(last=False)
            return True

    def __len__(self) -> int:
        return len(self._entries)


class ServiceVerifier:
    """Verifies an inbound assertion for ONE receiver audience against its ring,
    in the ADR 0053 section 5.4 order: parse, key, signature, claims, request
    binding, replay, then route authorization."""

    def __init__(
        self,
        ring: tuple[VerificationKey, ...],
        *,
        caller: str,
        audience: str,
        route_scopes: Mapping[tuple[str, str], str],
        service_scopes: Mapping[str, frozenset[str]],
        replay: ReplayCache | None = None,
        enforce_key_age: bool = True,
        clock: Callable[[], datetime] = _now,
    ) -> None:
        self._ring = {key.kid: key for key in ring}
        self._caller, self._audience = caller, audience
        self._route_scopes, self._service_scopes = route_scopes, service_scopes
        self._replay = replay if replay is not None else ReplayCache()
        self._enforce_key_age, self._clock = enforce_key_age, clock

    def verify(
        self, authorization: str | None, *, method: str, raw_path: str, query: str, body: bytes
    ) -> VerifiedService:
        now = self._clock()
        token = self._token(authorization)
        try:
            header_b64, payload_b64, signature_b64 = token.split(".")
            header = _strict_json_object(b64url_decode(header_b64))
            signature = b64url_decode(signature_b64)
        except (ValueError, TypeError):
            raise ServiceAuthError("malformed") from None
        if set(header) != _HEADER_MEMBERS or header.get("alg") != ALG or header.get("typ") != TYP:
            raise ServiceAuthError("malformed")
        kid = header.get("kid")
        if not isinstance(kid, str) or not _KID.fullmatch(kid):
            raise ServiceAuthError("malformed")

        key = self._ring.get(kid)
        if key is None:
            raise ServiceAuthError("unknown_kid")
        if (key.not_after is not None and now >= key.not_after) or (
            self._enforce_key_age and key.age_days(now) > MAX_KEY_AGE_DAYS
        ):
            raise ServiceAuthError("key_expired", kid)
        try:
            key.key.verify(signature, f"{header_b64}.{payload_b64}".encode("ascii"))
        except InvalidSignature:
            raise ServiceAuthError("bad_signature", kid) from None

        try:
            claims = _strict_json_object(b64url_decode(payload_b64))
        except (ValueError, TypeError):
            raise ServiceAuthError("malformed", kid) from None
        service = self._claims(claims, kid, now)
        self._binding(claims, kid, method=method, raw_path=raw_path, query=query, body=body)

        if not self._replay.first_use(
            service, claims["jti"], claims["exp"] + CLOCK_SKEW_SECONDS, now.timestamp()
        ):
            raise ServiceAuthError("replayed", kid)

        scope = self._route_scopes.get((method, raw_path))
        if scope is None or scope not in self._service_scopes.get(service, frozenset()):
            raise ServiceNotAuthorizedError(service, kid)
        return VerifiedService(service=service, kid=kid, scope=scope)

    @staticmethod
    def _token(authorization: str | None) -> str:
        if not authorization:
            raise ServiceAuthError("missing")
        scheme, _, token = authorization.partition(" ")
        if scheme.lower() != SCHEME:
            raise ServiceAuthError("missing")
        if not token or " " in token or len(token.encode()) > MAX_ASSERTION_BYTES:
            raise ServiceAuthError("malformed")
        return token

    def _claims(self, claims: dict[str, Any], kid: str, now: datetime) -> str:
        if not _REQUIRED_CLAIMS <= set(claims) <= _ALL_CLAIMS:
            raise ServiceAuthError("malformed", kid)
        integers = ("ver", "iat", "nbf", "exp")
        strings = ("iss", "sub", "aud", "jti", "htm", "htp", "bsh")
        if any(type(claims[name]) is not int for name in integers) or any(
            not isinstance(claims[name], str) for name in strings
        ):
            raise ServiceAuthError("malformed", kid)
        if "rid" in claims and (
            not isinstance(claims["rid"], str) or not _RID.fullmatch(claims["rid"])
        ):
            raise ServiceAuthError("malformed", kid)
        if claims["ver"] != VERSION or not re.fullmatch(r"[A-Za-z0-9_-]{16,64}", claims["jti"]):
            raise ServiceAuthError("malformed", kid)

        if (
            claims["iss"] not in SERVICES
            or claims["sub"] != claims["iss"]
            or claims["iss"] != self._caller
        ):
            raise ServiceAuthError("unknown_service", kid)
        if claims["aud"] != self._audience:
            raise ServiceAuthError("wrong_audience", kid)

        iat, nbf, exp, current = claims["iat"], claims["nbf"], claims["exp"], now.timestamp()
        if not iat <= nbf < exp:
            raise ServiceAuthError("malformed", kid)
        if exp - iat > MAX_LIFETIME_SECONDS:
            raise ServiceAuthError("lifetime_exceeded", kid)
        if iat - CLOCK_SKEW_SECONDS > current or nbf - CLOCK_SKEW_SECONDS > current:
            raise ServiceAuthError("not_yet_valid", kid)
        if current >= exp + CLOCK_SKEW_SECONDS:
            raise ServiceAuthError("expired", kid)
        return str(claims["iss"])

    @staticmethod
    def _binding(
        claims: dict[str, Any], kid: str, *, method: str, raw_path: str, query: str, body: bytes
    ) -> None:
        if query or not _canonical_path(raw_path):
            raise ServiceAuthError("request_mismatch", kid)
        if not hmac.compare_digest(claims["htm"], method) or not hmac.compare_digest(
            claims["htp"], raw_path
        ):
            raise ServiceAuthError("request_mismatch", kid)
        if not hmac.compare_digest(claims["bsh"], body_digest(body)):
            raise ServiceAuthError("body_digest_mismatch", kid)
