from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_liveness_returns_ok_and_leaks_no_infrastructure_detail() -> None:
    response = client.get("/health/live")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_readiness_returns_ok_when_configuration_is_valid() -> None:
    response = client.get("/health/ready")
    assert response.status_code == 200
    assert response.json()["status"] == "ok"


def test_complete_requires_a_service_assertion_but_health_does_not() -> None:
    response = client.post(
        "/v1/complete",
        json={"agent": "phase0b-proof-agent", "context_token": "t", "prompt": "hello"},
    )
    assert response.status_code == 401
    assert response.json() == {"error": {"code": "service_authentication_failed"}}
    # ADR 0053 section 10.2: probes need no credential (private network only).
    assert client.get("/health/live").status_code == 200
    assert client.get("/health/ready").status_code == 200


# The old "service token alone is enough" test is gone on purpose: that was
# gap G1. Completions now need a Laravel-verified context token -- see
# tests/test_complete_fail_closed.py.
