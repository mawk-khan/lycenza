"""ADR 0053 (Phase 0O.7A): Ed25519 service assertions at the Gateway.

Keys are generated at runtime (tests/service_keys.py). Time is injected; there
is no sleep anywhere.
"""

import base64
import hashlib
import itertools
import json
import logging
import threading
from datetime import datetime, timedelta
from typing import Any
from unittest.mock import AsyncMock, patch

import httpx
import pytest
from fastapi.testclient import TestClient

from app.core import security
from app.core.logging import sanitize, scrub
from app.core.service_auth import (
    AI_GATEWAY,
    AUDIENCE_AI_GATEWAY,
    AUDIENCE_PLATFORM_INTERNAL_AI,
    PLATFORM,
    ROUTE_SCOPES,
    SERVICE_SCOPES,
    ReplayCache,
    ServiceAuthError,
    ServiceKeyConfigError,
    ServiceNotAuthorizedError,
    ServiceSigner,
    ServiceVerifier,
    b64url_encode,
    parse_signing_key,
    parse_verification_ring,
)
from app.main import app
from tests.service_keys import GATEWAY_KEY, PLATFORM_KEY, TestKey, at, ring, signed

client = TestClient(app)
T0 = at("2026-10-01T12:00:00")
PATH = "/v1/complete"
BODY = b'{"agent":"phase0b-proof-agent","context_token":"t","prompt":"p"}'


class Clock:
    def __init__(self, now: datetime = T0) -> None:
        self.now = now

    def __call__(self) -> datetime:
        return self.now

    def advance(self, **delta: float) -> None:
        self.now += timedelta(**delta)


def key(kid: str, created: str = "2026-09-30") -> TestKey:
    return TestKey(kid, created)


def verifier(
    *public: dict[str, Any], clock: Clock, replay: ReplayCache | None = None, **kwargs: Any
) -> ServiceVerifier:
    return ServiceVerifier(
        parse_verification_ring(ring(*public), clock()),
        caller=kwargs.pop("caller", PLATFORM),
        audience=kwargs.pop("audience", AUDIENCE_AI_GATEWAY),
        route_scopes=kwargs.pop("route_scopes", ROUTE_SCOPES),
        service_scopes=kwargs.pop("service_scopes", SERVICE_SCOPES),
        replay=replay,
        clock=clock,
        **kwargs,
    )


def signer(k: TestKey, clock: Clock, **kwargs: Any) -> ServiceSigner:
    return ServiceSigner(
        parse_signing_key(k.private_jwk(), clock()),
        issuer=kwargs.pop("issuer", PLATFORM),
        audience=kwargs.pop("audience", AUDIENCE_AI_GATEWAY),
        clock=clock,
        **kwargs,
    )


def verify(v: ServiceVerifier, authorization: str | None, **request: Any) -> Any:
    return v.verify(
        authorization,
        method=request.get("method", "POST"),
        raw_path=request.get("raw_path", PATH),
        query=request.get("query", ""),
        body=request.get("body", BODY),
    )


def code(v: ServiceVerifier, authorization: str | None, **request: Any) -> str:
    with pytest.raises(ServiceAuthError) as refused:
        verify(v, authorization, **request)
    return refused.value.code


def forge(k: TestKey, header: dict[str, Any], claims: dict[str, Any], *, raw: bool = False) -> str:
    """A hand-built JWS (valid Ed25519 signature) for malformed-shape cases."""
    h = header if raw else json.dumps(header, separators=(",", ":"))
    signing_input = (
        b64url_encode(str(h).encode()) + "." + b64url_encode(json.dumps(claims).encode())
    )
    private = parse_signing_key(k.private_jwk(), T0).private
    return (
        "Lycenza-Service "
        + signing_input
        + "."
        + b64url_encode(private.sign(signing_input.encode()))
    )


def claims(clock: Clock, **overrides: Any) -> dict[str, Any]:
    iat = int(clock().timestamp())
    base = {
        "ver": 1,
        "iss": PLATFORM,
        "sub": PLATFORM,
        "aud": AUDIENCE_AI_GATEWAY,
        "iat": iat,
        "nbf": iat,
        "exp": iat + 60,
        "jti": b64url_encode(b"0123456789abcdef"),
        "htm": "POST",
        "htp": PATH,
        "bsh": b64url_encode(hashlib.sha256(BODY).digest()),
    }
    base.update(overrides)
    return {k: v for k, v in base.items() if v is not None}


HEADER = {"alg": "EdDSA", "typ": "lycenza-service+jwt"}


# --- format ---------------------------------------------------------------------


def test_a_fresh_assertion_verifies_and_every_request_gets_a_new_one() -> None:
    clock, k = Clock(), key("platform-a")
    s, v = signer(k, clock), verifier(k.public(), clock=clock)
    first, second = (
        s.authorization("POST", "https://gw" + PATH, BODY),
        s.authorization("POST", "https://gw" + PATH, BODY),
    )
    assert first != second
    assert verify(v, first).service == PLATFORM
    assert verify(v, second).scope == "gateway.complete"
    header = json.loads(base64.urlsafe_b64decode(first.split()[1].split(".")[0] + "=="))
    assert header == {"alg": "EdDSA", "typ": "lycenza-service+jwt", "kid": "platform-a"}
    payload = json.loads(base64.urlsafe_b64decode(first.split()[1].split(".")[1] + "=="))
    assert set(payload) == {
        "ver",
        "iss",
        "sub",
        "aud",
        "iat",
        "nbf",
        "exp",
        "jti",
        "htm",
        "htp",
        "bsh",
    }
    assert payload["exp"] - payload["iat"] == 60
    for forbidden in ("school_id", "actor_id", "capabilities", "user", "context_token"):
        assert forbidden not in payload


@pytest.mark.parametrize(
    "header",
    [
        {"alg": "none", "typ": "lycenza-service+jwt", "kid": "platform-a"},
        {"alg": "HS256", "typ": "lycenza-service+jwt", "kid": "platform-a"},
        {"alg": "Ed25519", "typ": "lycenza-service+jwt", "kid": "platform-a"},
        {"alg": "EdDSA", "typ": "JWT", "kid": "platform-a"},
        {"alg": "EdDSA", "typ": "lycenza-service+jwt"},
        {**HEADER, "kid": "platform-a", "jku": "https://attacker.example/keys"},
        {**HEADER, "kid": "platform-a", "x5u": "https://attacker.example/cert"},
        {**HEADER, "kid": "platform-a", "jwk": {"kty": "OKP"}},
        {**HEADER, "kid": "platform-a", "crit": ["exp"]},
        {**HEADER, "kid": "Platform A!"},
    ],
)
def test_the_header_is_exactly_alg_typ_kid(header: dict[str, Any]) -> None:
    clock, k = Clock(), key("platform-a")
    assert code(verifier(k.public(), clock=clock), forge(k, header, claims(clock))) == "malformed"


def test_duplicate_members_non_canonical_base64_and_oversize_are_malformed() -> None:
    clock, k = Clock(), key("platform-a")
    v = verifier(k.public(), clock=clock)
    duplicate = '{"alg":"EdDSA","alg":"EdDSA","typ":"lycenza-service+jwt","kid":"platform-a"}'
    assert code(v, forge(k, duplicate, claims(clock), raw=True)) == "malformed"  # type: ignore[arg-type]
    good = signer(k, clock).authorization("POST", "https://gw" + PATH, BODY)
    assert code(v, good + "=") == "malformed"
    assert code(v, good.replace(".", "..", 1)) == "malformed"
    assert code(v, "Lycenza-Service " + "a" * 2049) == "malformed"
    assert code(v, good.replace("Lycenza-Service ", "Lycenza-Service  ")) == "malformed"


def test_the_scheme_is_dedicated_and_case_insensitive() -> None:
    clock, k = Clock(), key("platform-a")
    v = verifier(k.public(), clock=clock)
    s = signer(k, clock)
    token = s.assertion("POST", "https://gw" + PATH, BODY)
    assert code(v, None) == "missing"
    assert code(v, "Bearer " + token) == "missing"
    assert code(v, token) == "missing"
    assert verify(v, "lycenza-service " + token).kid == "platform-a"


@pytest.mark.parametrize(
    ("change", "expected"),
    [
        ({"school_id": "0199aaaa-0000-7000-8000-000000000001"}, "malformed"),
        ({"ver": 2}, "malformed"),
        ({"ver": True}, "malformed"),
        ({"iat": "1"}, "malformed"),
        ({"jti": None}, "malformed"),
        ({"sub": AI_GATEWAY}, "unknown_service"),
        ({"iss": AI_GATEWAY, "sub": AI_GATEWAY}, "unknown_service"),
        ({"iss": "lycenza", "sub": "lycenza"}, "unknown_service"),
        ({"aud": "lycenza"}, "wrong_audience"),
        ({"aud": AUDIENCE_PLATFORM_INTERNAL_AI}, "wrong_audience"),
    ],
)
def test_the_claim_set_is_closed_and_bound_to_the_catalog(
    change: dict[str, Any], expected: str
) -> None:
    clock, k = Clock(), key("platform-a")
    assert (
        code(
            verifier(k.public(), clock=clock),
            forge(k, {**HEADER, "kid": "platform-a"}, claims(clock, **change)),
        )
        == expected
    )


# --- time -----------------------------------------------------------------------


def test_time_rules() -> None:
    clock, k = Clock(), key("platform-a")
    v = verifier(k.public(), clock=clock, replay=None)
    t = int(clock().timestamp())

    counter = itertools.count()

    def assertion(**change: Any) -> str:
        jti = f"time-rules-jti-{next(counter):06d}"
        return forge(k, {**HEADER, "kid": "platform-a"}, claims(clock, jti=jti, **change))

    assert code(v, assertion(exp=t + 121)) == "lifetime_exceeded"
    assert code(v, assertion(iat=t + 31, nbf=t + 31, exp=t + 91)) == "not_yet_valid"
    assert code(v, assertion(iat=t - 100, nbf=t - 100, exp=t - 30)) == "expired"
    assert code(v, assertion(nbf=t - 1)) == "malformed"  # nbf before iat
    assert code(v, assertion(exp=t)) == "malformed"  # exp not after nbf
    within_skew = forge(
        k, {**HEADER, "kid": "platform-a"}, claims(clock, iat=t + 29, nbf=t + 29, exp=t + 89)
    )
    assert verify(v, within_skew).service == PLATFORM
    late_but_skewed = forge(
        k,
        {**HEADER, "kid": "platform-a"},
        claims(clock, iat=t - 80, nbf=t - 80, exp=t - 20, jti="late-but-skewed-00001"),
    )
    assert verify(v, late_but_skewed).service == PLATFORM


# --- request binding --------------------------------------------------------------


@pytest.mark.parametrize(
    ("request_change", "expected"),
    [
        ({"method": "PUT"}, "request_mismatch"),
        ({"raw_path": "/v1/tools/invoke"}, "request_mismatch"),
        ({"raw_path": "/v1/complete/"}, "request_mismatch"),
        ({"raw_path": "/v1/%63omplete"}, "request_mismatch"),
        ({"raw_path": "/v1//complete"}, "request_mismatch"),
        ({"raw_path": "/v1/./complete"}, "request_mismatch"),
        ({"query": "a=1"}, "request_mismatch"),
        ({"body": BODY + b" "}, "body_digest_mismatch"),
        ({"body": b""}, "body_digest_mismatch"),
    ],
)
def test_the_assertion_is_bound_to_method_path_and_exact_body(
    request_change: dict[str, Any], expected: str
) -> None:
    clock, k = Clock(), key("platform-a")
    v = verifier(k.public(), clock=clock)
    authorization = signer(k, clock).authorization("POST", "https://gw" + PATH, BODY)
    assert code(v, authorization, **request_change) == expected


def test_an_empty_body_uses_the_digest_of_empty_bytes() -> None:
    clock, k = Clock(), key("platform-a")
    authorization = signer(k, clock).authorization("POST", "https://gw" + PATH, b"")
    assert verify(verifier(k.public(), clock=clock), authorization, body=b"").service == PLATFORM


def test_a_signer_refuses_a_non_canonical_target() -> None:
    clock, k = Clock(), key("platform-a")
    s = signer(k, clock)
    for url in (
        "https://gw/v1/complete?x=1",
        "https://gw/v1/complete/",
        "https://gw/v1/%63omplete",
        "https://gw/v1//x",
    ):
        with pytest.raises(ServiceKeyConfigError):
            s.assertion("POST", url, BODY)


# --- keys and rings -----------------------------------------------------------------


def test_signing_key_validation() -> None:
    k = key("platform-a")
    other = key("platform-b")
    for bad in (
        k.private_jwk(crv="X25519"),
        k.private_jwk(kty="EC"),
        k.private_jwk(d=k.d + "A"),
        k.private_jwk(d=k.d[:-2]),
        k.private_jwk(d="not*base64"),
        k.private_jwk(x=other.x),  # x does not belong to d
        k.private_jwk(kid="Bad Kid"),
        k.private_jwk(created="2026-13-01"),
        k.private_jwk(created="2027-01-01"),  # in the future
        json.dumps({**json.loads(k.private_jwk()), "use": "sig"}),
    ):
        with pytest.raises(ServiceKeyConfigError):
            parse_signing_key(bad, T0)


def test_ring_validation() -> None:
    a, b = key("platform-a"), key("platform-b")
    soon = (T0 + timedelta(hours=2)).strftime("%Y-%m-%dT%H:%M:%SZ")
    past = (T0 - timedelta(seconds=1)).strftime("%Y-%m-%dT%H:%M:%SZ")
    too_far = (T0 + timedelta(hours=24, seconds=1)).strftime("%Y-%m-%dT%H:%M:%SZ")
    exactly_24h = (T0 + timedelta(hours=24)).strftime("%Y-%m-%dT%H:%M:%SZ")
    assert len(parse_verification_ring(ring(a.public(), b.public(not_after=exactly_24h)), T0)) == 2
    cases = {
        "[]": "verification_keys_missing",
        "{}": "verification_keys_invalid",
        ring(a.public(crv="X25519")): "verification_keys_invalid",
        ring(a.public(x=a.x[:-1])): "verification_keys_invalid",
        ring({**a.public(), "d": a.d}): "verification_keys_private_material",
        ring(a.public(), a.public(not_after=soon)): "verification_keys_duplicate_kid",
        ring(a.public(), b.public(not_after=past)): "verification_key_transition_expired",
        ring(a.public(), b.public(not_after=too_far)): "verification_key_transition_too_long",
        ring(a.public(not_after=soon), b.public(not_after=soon)): "verification_keys_steady_key",
    }
    for value, expected in cases.items():
        with pytest.raises(ServiceKeyConfigError) as refused:
            parse_verification_ring(value, T0)
        assert refused.value.code == expected, value


def test_an_unknown_kid_is_refused_and_no_key_is_ever_fetched() -> None:
    clock, a, b = Clock(), key("platform-a"), key("platform-b")
    authorization = signer(b, clock).authorization("POST", "https://gw" + PATH, BODY)
    assert code(verifier(a.public(), clock=clock), authorization) == "unknown_kid"


def test_a_signature_by_another_key_under_a_known_kid_is_refused() -> None:
    clock, a = Clock(), key("platform-a")
    impostor = TestKey("platform-a", "2026-09-30")
    authorization = signer(impostor, clock).authorization("POST", "https://gw" + PATH, BODY)
    assert code(verifier(a.public(), clock=clock), authorization) == "bad_signature"


def test_keys_older_than_90_days_neither_sign_nor_verify_in_production() -> None:
    clock = Clock()
    old = key("platform-old", (T0 - timedelta(days=91)).date().isoformat())
    with pytest.raises(ServiceKeyConfigError):
        signer(old, clock).assertion("POST", "https://gw" + PATH, BODY)
    lenient = signer(old, clock, enforce_key_age=False).authorization(
        "POST", "https://gw" + PATH, BODY
    )
    assert code(verifier(old.public(), clock=clock), lenient) == "key_expired"
    assert (
        verify(verifier(old.public(), clock=clock, enforce_key_age=False), lenient).service
        == PLATFORM
    )


# --- rotation ---------------------------------------------------------------------


def test_routine_rotation_rollback_and_expiry_of_the_old_key() -> None:
    clock = Clock()
    old, new = key("platform-20260801-1", "2026-09-01"), key("platform-20261001-1", "2026-10-01")
    old_signer, new_signer = signer(old, clock), signer(new, clock)

    def call(s: ServiceSigner, v: ServiceVerifier) -> str:
        try:
            return verify(v, s.authorization("POST", "https://gw" + PATH, BODY)).kid
        except ServiceAuthError as exc:
            return exc.code

    # 1. steady: the receiver trusts only the old key.
    receiver = verifier(old.public(), clock=clock)
    assert call(old_signer, receiver) == old.kid
    assert call(new_signer, receiver) == "unknown_kid"

    # 2. EVERY receiver replica stages the new key (transitional, <= 24 h)
    #    before any caller switches -- no moment without an accepted key.
    staged = ring(
        old.public(),
        new.public(not_after=(clock() + timedelta(hours=24)).strftime("%Y-%m-%dT%H:%M:%SZ")),
    )
    replicas = [verifier(*json.loads(staged), clock=clock) for _ in range(3)]
    in_flight = old_signer.authorization(
        "POST", "https://gw" + PATH, BODY
    )  # signed just before cutover
    assert all(call(old_signer, r) == old.kid for r in replicas)

    # 3. callers switch; new traffic verifies everywhere, the in-flight old one still does.
    assert all(call(new_signer, r) == new.kid for r in replicas)
    assert verify(replicas[0], in_flight).kid == old.kid

    # 4. rollback (planned rotation only): callers switch back while both are trusted.
    assert all(call(old_signer, r) == old.kid for r in replicas)

    # 5. promote: new becomes steady, old transitional for in-flight assertions only.
    clock.advance(minutes=5)
    retire = ring(
        new.public(),
        old.public(not_after=(clock() + timedelta(minutes=3)).strftime("%Y-%m-%dT%H:%M:%SZ")),
    )
    retiring = verifier(*json.loads(retire), clock=clock)
    assert call(new_signer, retiring) == new.kid
    assert call(old_signer, retiring) == old.kid
    clock.advance(minutes=3)
    assert (
        call(old_signer, retiring) == "key_expired"
    )  # not_after passed: refused even though still configured
    assert call(new_signer, retiring) == new.kid

    # 6. removed.
    final = verifier(new.public(), clock=clock)
    assert call(old_signer, final) == "unknown_kid"
    assert call(new_signer, final) == new.kid


def test_emergency_revocation_has_no_overlap() -> None:
    clock = Clock()
    compromised, replacement = key("platform-a"), key("platform-b", "2026-10-01")
    before = verifier(compromised.public(), clock=clock)
    stolen = signer(compromised, clock).authorization("POST", "https://gw" + PATH, BODY)
    assert (
        verify(
            before, signer(compromised, clock).authorization("POST", "https://gw" + PATH, BODY)
        ).kid
        == "platform-a"
    )

    after = verifier(
        replacement.public(), clock=clock
    )  # restarted receiver: key removed, not "previous"
    assert code(after, stolen) == "unknown_kid"  # refused at once, its exp notwithstanding
    assert (
        verify(
            after, signer(replacement, clock).authorization("POST", "https://gw" + PATH, BODY)
        ).kid
        == "platform-b"
    )


# --- replay (best effort; the residual is explicit) -----------------------------------


def test_a_replay_to_the_same_process_is_refused() -> None:
    clock, k = Clock(), key("platform-a")
    v = verifier(k.public(), clock=clock)
    authorization = signer(k, clock).authorization("POST", "https://gw" + PATH, BODY)
    assert verify(v, authorization).service == PLATFORM
    assert code(v, authorization) == "replayed"


def test_accepted_residual_a_replay_to_another_replica_succeeds_within_the_window() -> None:
    """ACCEPTED ADR 0053 v1 BEHAVIOUR (section 5.5), not a defect: the Gateway has
    no shared replay store, so the same assertion is accepted once by EACH replica
    (process) until exp + 30 s. Reconsider before Phase 0M activates a real provider."""
    clock, k = Clock(), key("platform-a")
    replica_1 = verifier(k.public(), clock=clock, replay=ReplayCache())
    replica_2 = verifier(k.public(), clock=clock, replay=ReplayCache())
    authorization = signer(k, clock).authorization("POST", "https://gw" + PATH, BODY)
    assert verify(replica_1, authorization).service == PLATFORM
    assert verify(replica_2, authorization).service == PLATFORM
    clock.advance(seconds=90)  # exp (60 s) + skew (30 s)
    assert code(verifier(k.public(), clock=clock), authorization) == "expired"


def test_the_replay_cache_is_bounded_and_expiry_aware() -> None:
    cache = ReplayCache(max_entries=3)
    for n in range(10):
        assert cache.first_use(PLATFORM, f"jti-{n:020d}", expires_at=100.0, now=0.0)
    assert len(cache) == 3
    assert cache.first_use(PLATFORM, "jti-late-00000000000", expires_at=300.0, now=200.0)
    assert len(cache) == 1  # everything expired was purged


def test_the_replay_cache_is_thread_safe() -> None:
    cache, results = ReplayCache(), []
    barrier = threading.Barrier(16)

    def attempt() -> None:
        barrier.wait()
        results.append(
            cache.first_use(PLATFORM, "same-jti-000000000000", expires_at=100.0, now=0.0)
        )

    threads = [threading.Thread(target=attempt) for _ in range(16)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    assert results.count(True) == 1


# --- route authorization --------------------------------------------------------------


def test_route_authorization_is_separate_from_authentication() -> None:
    clock, k = Clock(), key("platform-a")
    v = verifier(
        k.public(), clock=clock, service_scopes={PLATFORM: frozenset({"gateway.tools.invoke"})}
    )
    with pytest.raises(ServiceNotAuthorizedError):
        verify(v, signer(k, clock).authorization("POST", "https://gw" + PATH, BODY))
    assert ROUTE_SCOPES == {
        ("POST", "/v1/tools/invoke"): "gateway.tools.invoke",
        ("POST", "/v1/complete"): "gateway.complete",
    }
    assert SERVICE_SCOPES == {PLATFORM: frozenset({"gateway.tools.invoke", "gateway.complete"})}


def test_every_business_route_is_in_the_closed_catalog() -> None:
    from fastapi.routing import APIRoute

    business = {
        (method, route.path)
        for route in app.routes
        if isinstance(route, APIRoute) and not route.path.startswith("/health/")
        for method in route.methods
    }
    assert business == set(ROUTE_SCOPES)


# --- over HTTP ------------------------------------------------------------------------


def test_other_credentials_never_authenticate_a_business_route() -> None:
    body = {"agent": "phase0b-proof-agent", "context_token": "t", "prompt": "p"}
    valid = signed(PATH, body)
    token = valid["headers"]["Authorization"].split()[1]
    for headers in (
        {"X-Service-Token": "dev-local-only-token"},
        {"Authorization": "Bearer dev-local-only-token"},
        {"Authorization": "Bearer " + token},
        {"Authorization": "Bearer 1|sanctum-personal-access-token"},
        {"Authorization": "Bearer lyc_pk_partnerkeyid.secret"},
        {"Authorization": "Bearer metrics-scrape-token-value-0000000000000"},
        {"Authorization": "Lycenza-Service t"},  # an AI context token is not an assertion
    ):
        response = client.post(
            PATH, content=valid["content"], headers={"Content-Type": "application/json", **headers}
        )
        assert response.status_code == 401, headers
        assert response.json() == {"error": {"code": "service_authentication_failed"}}


def test_an_assertion_issued_by_the_gateway_identity_is_refused_here() -> None:
    gateway_signer = ServiceSigner(
        parse_signing_key(GATEWAY_KEY.private_jwk()),
        issuer=AI_GATEWAY,
        audience=AUDIENCE_AI_GATEWAY,
    )
    response = client.post(PATH, **signed(PATH, {"agent": "x"}, signer=gateway_signer))
    assert response.status_code == 401


def test_a_replayed_request_is_refused_by_the_same_process() -> None:
    request = signed(PATH, {"agent": "no-such-agent", "context_token": "t", "prompt": "p"})
    first = client.post(PATH, **request)
    assert first.status_code == 403  # authenticated; then the agent is refused
    replay = client.post(PATH, **request)
    assert replay.status_code == 401


def test_the_body_seen_by_the_application_is_the_signed_body() -> None:
    request = signed(PATH, {"agent": "no-such-agent", "context_token": "t", "prompt": "p"})
    tampered = {
        **request,
        "content": request["content"].replace(b"no-such-agent", b"phase0b-proof-agent"),
    }
    assert client.post(PATH, **tampered).status_code == 401


# --- outbound signer --------------------------------------------------------------------


def test_outbound_calls_carry_a_fresh_ai_gateway_assertion_bound_to_the_exact_bytes() -> None:
    request = httpx.Request("POST", "http://platform.test")
    answers = [
        httpx.Response(
            200, json={"authorization": {"schoolId": "s", "actorId": "a"}}, request=request
        ),
        httpx.Response(200, json={"audit": {"id": "a"}}, request=request),
    ]
    with patch("httpx.AsyncClient.post", new=AsyncMock(side_effect=answers)) as posted:
        client.post(
            PATH,
            **signed(PATH, {"agent": "phase0b-proof-agent", "context_token": "t", "prompt": "p"}),
        )
    assert posted.call_count == 2  # completions/authorize, then audit
    laravel = ServiceVerifier(
        parse_verification_ring(ring(GATEWAY_KEY.public())),
        caller=AI_GATEWAY,
        audience=AUDIENCE_PLATFORM_INTERNAL_AI,
        route_scopes={
            ("POST", "/api/internal/ai/completions/authorize"): "ai.completions.authorize",
            ("POST", "/api/internal/ai/audit"): "ai.audit.write",
        },
        service_scopes={AI_GATEWAY: frozenset({"ai.completions.authorize", "ai.audit.write"})},
    )
    seen = set()
    for call in posted.call_args_list:
        headers, body = call.kwargs["headers"], call.kwargs["content"]
        assert "X-Service-Token" not in headers
        assert headers["Authorization"].startswith("Lycenza-Service ")
        verified = laravel.verify(
            headers["Authorization"],
            method="POST",
            raw_path="/api/internal/ai" + call.args[0],
            query="",
            body=body,
        )
        seen.add(verified.scope)
        assert "school_id" not in json.loads(
            base64.urlsafe_b64decode(headers["Authorization"].split(".")[1] + "==")
        )
    assert seen == {"ai.completions.authorize", "ai.audit.write"}


def test_the_outbound_signer_uses_the_ai_gateway_identity() -> None:
    assert security.service_auth.signer.kid == GATEWAY_KEY.kid
    assert PLATFORM_KEY.kid != GATEWAY_KEY.kid


# --- logging -------------------------------------------------------------------------


def test_failures_log_a_closed_code_and_never_the_assertion(caplog) -> None:
    request = signed(PATH, {"agent": "x"})
    request["headers"]["Authorization"] += "x"
    token = request["headers"]["Authorization"].split()[1]
    with caplog.at_level(logging.DEBUG, logger="app.core.security"):
        assert client.post(PATH, **request).status_code == 401
    events = [r for r in caplog.records if r.getMessage() == "service_auth.failed"]
    assert events and events[-1].outcome in {"bad_signature", "malformed"}  # type: ignore[attr-defined]
    rendered = " ".join(str(vars(r)) for r in caplog.records)
    assert token not in rendered
    assert token.split(".")[2] not in rendered


def test_the_sanitizer_redacts_assertions_and_private_jwk_material() -> None:
    authorization = signed(PATH, {"agent": "x"})["headers"]["Authorization"]
    assert "eyJ" not in scrub(f"calling with {authorization}")
    assert authorization.split()[1] not in scrub(f"token={authorization.split()[1]}")
    assert GATEWAY_KEY.d not in scrub(GATEWAY_KEY.private_jwk())
    assert sanitize("x", "service_signing_key") == "[redacted]"
    assert sanitize("x", "jti") == "[redacted]"
