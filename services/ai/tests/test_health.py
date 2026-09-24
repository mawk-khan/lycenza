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


def test_complete_requires_service_token() -> None:
    response = client.post(
        "/v1/complete",
        json={"agent": "phase0b-proof-agent", "context_token": "t", "prompt": "hello"},
    )
    assert response.status_code == 401


# The old "service token alone is enough" test is gone on purpose: that was
# gap G1. Completions now need a Laravel-verified context token -- see
# tests/test_complete_fail_closed.py.
