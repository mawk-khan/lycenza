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
        json={"school_id": "t1", "agent": "test-agent", "prompt": "hello"},
    )
    assert response.status_code == 401


def test_complete_with_valid_token_uses_null_provider() -> None:
    response = client.post(
        "/v1/complete",
        json={"school_id": "t1", "agent": "test-agent", "prompt": "hello"},
        headers={"X-Service-Token": "dev-local-only-token"},
    )
    assert response.status_code == 200
    body = response.json()
    assert body["provider"] == "null"
