"""Phase 0O.6B: the Gateway's framework-level HTTP contract, pinned across the
security-only FastAPI 0.116.1 -> 0.133.0 / Starlette 0.47.3 -> 1.3.1 upgrade.
Endpoint behaviour (authorization, NullProvider, context verification, audit)
is covered by the endpoint-specific tests; this module guards what the
framework itself decides: routing, error shapes and status codes."""

import pytest
from fastapi.routing import APIRoute
from fastapi.testclient import TestClient

from app.main import app
from tests.service_keys import platform_signer, signed

client = TestClient(app)
SECRET_INPUT = "canary-context-token-value-0o6b"
POST_ENDPOINTS = ["/v1/complete", "/v1/tools/invoke"]


def test_the_route_table_is_unchanged() -> None:
    routes = sorted(
        (r.path, tuple(sorted(r.methods))) for r in app.routes if isinstance(r, APIRoute)
    )
    assert routes == [
        ("/health/live", ("GET",)),
        ("/health/ready", ("GET",)),
        ("/v1/complete", ("POST",)),
        ("/v1/tools/invoke", ("POST",)),
    ]


@pytest.mark.parametrize("path", POST_ENDPOINTS)
def test_validation_errors_report_location_and_type_only(path: str) -> None:
    response = client.post(path, **signed(path, {"context_token": SECRET_INPUT, "unexpected": 1}))
    assert response.status_code == 422
    body = response.json()
    assert set(body) == {"detail"}
    assert body["detail"], "at least one missing field is reported"
    for error in body["detail"]:
        assert set(error) == {"loc", "type"}
        assert isinstance(error["loc"], list)
    assert SECRET_INPUT not in response.text


@pytest.mark.parametrize("path", POST_ENDPOINTS)
def test_malformed_json_is_a_422_with_the_same_shape(path: str) -> None:
    body = b'{"agent": "x", ' + SECRET_INPUT.encode()
    response = client.post(
        path,
        headers={
            "Content-Type": "application/json",
            "Authorization": platform_signer().authorization(
                "POST", "http://gateway.test" + path, body
            ),
        },
        content=body,
    )
    assert response.status_code == 422
    assert all(set(error) == {"loc", "type"} for error in response.json()["detail"])
    assert SECRET_INPUT not in response.text


@pytest.mark.parametrize("path", POST_ENDPOINTS)
def test_a_missing_or_wrong_service_credential_is_401_with_a_fixed_body(path: str) -> None:
    """ADR 0053: authentication precedes parsing -- even malformed JSON from an
    unauthenticated caller is the uniform 401, never a 422."""
    for headers in ({}, {"X-Service-Token": "wrong"}, {"Authorization": "Bearer wrong"}):
        response = client.post(path, headers=headers, content=b"{not json")
        assert response.status_code == 401
        assert response.json() == {"error": {"code": "service_authentication_failed"}}


def test_unknown_paths_and_methods() -> None:
    assert client.get("/v1/unknown").status_code == 404
    assert client.get("/v1/complete").status_code == 405
    assert client.post("/health/live").status_code == 405


@pytest.mark.parametrize("path", ["/health/live", "/health/ready"])
def test_health_endpoints_answer_json(path: str) -> None:
    response = client.get(path)
    assert response.status_code == 200
    assert response.headers["content-type"].startswith("application/json")
