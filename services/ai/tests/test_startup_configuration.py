"""Phase 0O.1: the AI Gateway fails closed on its service token.

Refuses to start (and reports not ready, HTTP 503) with no token in any
environment, or with the public development token outside local/testing.
Liveness stays independent of configuration.
"""

import os
import subprocess
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.core.config import Settings, settings
from app.core.startup import (
    DEVELOPMENT_SERVICE_TOKEN,
    UnsafeConfigurationError,
    assert_safe_configuration,
    configuration_violations,
)
from app.main import app

ROOT = Path(__file__).resolve().parents[1]
client = TestClient(app)


def make(**values: str) -> Settings:
    return Settings(_env_file=None, **values)  # type: ignore[call-arg]


def test_unset_values_fail_closed_rather_than_falling_back_to_the_dev_token(monkeypatch) -> None:
    monkeypatch.delenv("SERVICE_TOKEN", raising=False)
    monkeypatch.delenv("ENVIRONMENT", raising=False)
    defaults = make()
    assert defaults.service_token == ""
    assert defaults.environment == "production"
    assert configuration_violations(defaults) == ["service_token_missing"]


@pytest.mark.parametrize("environment", ["production", "staging", "", "Production", "prod"])
def test_the_dev_token_is_refused_outside_local_and_testing(environment: str) -> None:
    config = make(environment=environment, service_token=DEVELOPMENT_SERVICE_TOKEN)
    with pytest.raises(UnsafeConfigurationError) as refused:
        assert_safe_configuration(config)
    assert refused.value.violations == ["service_token_development_value"]


@pytest.mark.parametrize("environment", ["local", "testing", "LOCAL", " testing "])
def test_the_dev_token_still_works_locally(environment: str) -> None:
    config = make(environment=environment, service_token=DEVELOPMENT_SERVICE_TOKEN)
    assert_safe_configuration(config)


@pytest.mark.parametrize("environment", ["local", "testing", "production"])
@pytest.mark.parametrize("token", ["", "   "])
def test_a_missing_token_is_refused_in_every_environment(environment: str, token: str) -> None:
    config = make(environment=environment, service_token=token)
    assert configuration_violations(config) == ["service_token_missing"]


def test_a_real_token_is_accepted_in_production() -> None:
    assert_safe_configuration(make(environment="production", service_token="a-real-deployed-token"))


def _import_main(env: dict[str, str], tmp_path: Path) -> subprocess.CompletedProcess[str]:
    # cwd is an empty directory so no developer .env file is read.
    clean = {"PATH": os.environ.get("PATH", ""), "PYTHONPATH": str(ROOT), **env}
    return subprocess.run(
        [sys.executable, "-c", "import app.main"],
        cwd=tmp_path,
        env=clean,
        capture_output=True,
        text=True,
        timeout=60,
    )


def test_the_process_refuses_to_start_without_a_token(tmp_path: Path) -> None:
    result = _import_main({}, tmp_path)
    assert result.returncode != 0
    assert "service_token_missing" in result.stderr


def test_the_process_refuses_the_dev_token_in_production_and_never_prints_it(
    tmp_path: Path,
) -> None:
    result = _import_main(
        {"ENVIRONMENT": "production", "SERVICE_TOKEN": DEVELOPMENT_SERVICE_TOKEN}, tmp_path
    )
    assert result.returncode != 0
    assert "service_token_development_value" in result.stderr
    assert DEVELOPMENT_SERVICE_TOKEN not in result.stderr + result.stdout


def test_the_process_starts_locally_and_in_production_with_a_real_token(tmp_path: Path) -> None:
    local = _import_main(
        {"ENVIRONMENT": "local", "SERVICE_TOKEN": DEVELOPMENT_SERVICE_TOKEN}, tmp_path
    )
    assert local.returncode == 0, local.stderr
    production = _import_main(
        {"ENVIRONMENT": "production", "SERVICE_TOKEN": "a-real-deployed-token"}, tmp_path
    )
    assert production.returncode == 0, production.stderr


def test_readiness_is_503_when_unsafe_and_liveness_is_unaffected(monkeypatch) -> None:
    monkeypatch.setattr(settings, "service_token", "")
    ready = client.get("/health/ready")
    assert ready.status_code == 503
    assert ready.json() == {"status": "unhealthy"}
    assert client.get("/health/live").status_code == 200

    monkeypatch.setattr(settings, "service_token", DEVELOPMENT_SERVICE_TOKEN)
    monkeypatch.setattr(settings, "environment", "production")
    ready = client.get("/health/ready")
    assert ready.status_code == 503
    assert DEVELOPMENT_SERVICE_TOKEN not in ready.text

    monkeypatch.setattr(settings, "environment", "testing")
    assert client.get("/health/ready").status_code == 200


def test_an_empty_expected_token_never_authenticates_an_empty_header(monkeypatch) -> None:
    monkeypatch.setattr(settings, "service_token", "")
    response = client.post(
        "/v1/complete",
        headers={"X-Service-Token": ""},
        json={"agent": "phase0b-proof-agent", "context_token": "t", "prompt": "hello"},
    )
    assert response.status_code == 401


def test_the_real_provider_gate_stays_closed_by_default() -> None:
    assert make(environment="production", service_token="x").real_providers_allowed is False
    assert make(real_providers_enabled="yes").real_providers_allowed is False
    assert make(real_providers_enabled=" TRUE ").real_providers_allowed is True
