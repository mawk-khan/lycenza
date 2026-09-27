"""Phase 0O.1 / 0O.7A: the AI Gateway fails closed on its ADR 0053 service keys.

Refuses to start (and reports not ready, HTTP 503) without a valid signing key
and verification ring in any environment, with a development key, an expired
key or a plaintext Laravel URL outside local/testing, and whenever the retired
shared service token is still configured. Liveness stays independent of
configuration. Violations are codes only, never values.
"""

import os
import subprocess
import sys
from datetime import timedelta
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.core import service_auth
from app.core.config import Settings, settings
from app.core.startup import (
    UnsafeConfigurationError,
    assert_safe_configuration,
    configuration_violations,
)
from app.main import app
from tests.service_keys import GATEWAY_KEY, PLATFORM_KEY, TestKey, at, ring

ROOT = Path(__file__).resolve().parents[1]
client = TestClient(app)
NOW = at("2026-10-01T12:00:00")
HTTPS = "https://platform.internal/api/internal/ai"


def make(**values: str) -> Settings:
    base = {
        "environment": "production",
        "service_signing_key": TestKey("gw-20260930-1", "2026-09-30").private_jwk(),
        "platform_verification_keys": ring(TestKey("platform-20260930-1", "2026-09-30").public()),
        "erp_contract_base_url": HTTPS,
    }
    base.update(values)
    return Settings(_env_file=None, **base)  # type: ignore[call-arg]


def test_unset_values_fail_closed(monkeypatch) -> None:
    for name in (
        "SERVICE_SIGNING_KEY",
        "PLATFORM_VERIFICATION_KEYS",
        "ENVIRONMENT",
        "SERVICE_TOKEN",
    ):
        monkeypatch.delenv(name, raising=False)
    defaults = Settings(_env_file=None)  # type: ignore[call-arg]
    assert defaults.environment == "production"
    assert configuration_violations(defaults, NOW) == [
        "service_signing_key_missing",
        "verification_keys_missing",
        "erp_contract_url_not_https",
    ]


def test_a_valid_production_configuration_is_accepted() -> None:
    assert configuration_violations(make(), NOW) == []


@pytest.mark.parametrize("environment", ["local", "testing", "production"])
def test_the_retired_shared_token_is_refused_in_every_environment(environment: str) -> None:
    config = make(environment=environment, service_token="dev-local-only-token")
    assert configuration_violations(config, NOW) == ["legacy_service_token_configured"]


@pytest.mark.parametrize("environment", ["production", "staging", "", "Production", "prod"])
def test_development_keys_are_refused_outside_local_and_testing(
    environment: str, monkeypatch
) -> None:
    by_prefix = make(
        environment=environment,
        service_signing_key=TestKey("dev-local-only-ai-gateway-9", "2026-09-30").private_jwk(),
        platform_verification_keys=ring(
            TestKey("dev-local-only-platform-9", "2026-09-30").public()
        ),
    )
    assert configuration_violations(by_prefix, NOW) == [
        "service_signing_key_development",
        "verification_keys_development",
    ]
    # The committed development PUBLIC keys are refused even under another kid.
    signer, platform = TestKey("gw-x-1", "2026-09-30"), TestKey("platform-x-1", "2026-09-30")
    monkeypatch.setattr(service_auth, "DEVELOPMENT_PUBLIC_KEYS", frozenset({signer.x, platform.x}))
    by_fingerprint = make(
        environment=environment,
        service_signing_key=signer.private_jwk(),
        platform_verification_keys=ring(platform.public()),
    )
    assert configuration_violations(by_fingerprint, NOW) == [
        "service_signing_key_development",
        "verification_keys_development",
    ]


@pytest.mark.parametrize("environment", ["local", "testing", "LOCAL", " testing "])
def test_development_keys_and_http_still_work_locally(environment: str) -> None:
    config = make(
        environment=environment,
        service_signing_key=TestKey("dev-local-only-ai-gateway-9").private_jwk(),
        platform_verification_keys=ring(TestKey("dev-local-only-platform-9").public()),
        erp_contract_base_url="http://platform:8000/api/internal/ai",
    )
    assert configuration_violations(config) == []


def test_a_signing_key_older_than_90_days_is_refused_in_production() -> None:
    created = (NOW - timedelta(days=91)).date().isoformat()
    config = make(service_signing_key=TestKey("gw-old-1", created).private_jwk())
    assert configuration_violations(config, NOW) == ["service_signing_key_expired"]
    exactly_90 = (NOW - timedelta(days=90)).date().isoformat()
    assert (
        configuration_violations(
            make(service_signing_key=TestKey("gw-90-1", exactly_90).private_jwk()), NOW
        )
        == []
    )


@pytest.mark.parametrize(
    ("signing_key", "code"),
    [
        ("not json", "service_signing_key_invalid"),
        ('{"kty":"OKP"}', "service_signing_key_invalid"),
    ],
)
def test_malformed_signing_keys_are_refused(signing_key: str, code: str) -> None:
    assert configuration_violations(make(service_signing_key=signing_key), NOW) == [code]


def test_ring_rule_violations_are_refused() -> None:
    a, b, c = (TestKey(f"platform-{n}", "2026-09-30") for n in "abc")
    soon = (NOW + timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M:%SZ")
    cases = {
        "[]": "verification_keys_missing",
        ring(
            a.public(), b.public(not_after=soon), c.public(not_after=soon)
        ): "verification_keys_too_many",
        ring(a.public(), a.public(not_after=soon)): "verification_keys_duplicate_kid",
        ring(a.public(), b.public()): "verification_keys_steady_key",
        ring({**a.public(), "d": a.d}): "verification_keys_private_material",
    }
    for value, code in cases.items():
        assert configuration_violations(make(platform_verification_keys=value), NOW) == [code], code


def test_a_plaintext_laravel_url_is_refused_in_production() -> None:
    config = make(erp_contract_base_url="http://platform:8000/api/internal/ai")
    assert configuration_violations(config, NOW) == ["erp_contract_url_not_https"]


def test_violations_never_carry_a_value() -> None:
    with pytest.raises(UnsafeConfigurationError) as refused:
        assert_safe_configuration(make(service_token="canary-legacy-token-value"))
    assert "canary-legacy-token-value" not in str(refused.value)
    assert GATEWAY_KEY.d not in str(refused.value)


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


def test_the_process_refuses_to_start_without_keys(tmp_path: Path) -> None:
    result = _import_main({}, tmp_path)
    assert result.returncode != 0
    assert "service_signing_key_missing" in result.stderr


def test_the_process_refuses_the_legacy_token_and_never_prints_it(tmp_path: Path) -> None:
    result = _import_main(
        {
            "ENVIRONMENT": "testing",
            "SERVICE_TOKEN": "canary-legacy-token-value",
            "SERVICE_SIGNING_KEY": GATEWAY_KEY.private_jwk(),
            "PLATFORM_VERIFICATION_KEYS": ring(PLATFORM_KEY.public()),
        },
        tmp_path,
    )
    assert result.returncode != 0
    assert "legacy_service_token_configured" in result.stderr
    assert "canary-legacy-token-value" not in result.stderr + result.stdout
    assert GATEWAY_KEY.d not in result.stderr + result.stdout


def test_the_process_starts_in_production_with_valid_keys(tmp_path: Path) -> None:
    production = _import_main(
        {
            "ENVIRONMENT": "production",
            "SERVICE_SIGNING_KEY": GATEWAY_KEY.private_jwk(),
            "PLATFORM_VERIFICATION_KEYS": ring(PLATFORM_KEY.public()),
            "ERP_CONTRACT_BASE_URL": HTTPS,
        },
        tmp_path,
    )
    assert production.returncode == 0, production.stderr


def test_readiness_is_503_when_unsafe_and_liveness_is_unaffected(monkeypatch) -> None:
    monkeypatch.setattr(settings, "service_token", "canary-legacy-token-value")
    ready = client.get("/health/ready")
    assert ready.status_code == 503
    assert ready.json() == {"status": "unhealthy"}
    assert "canary" not in ready.text
    assert client.get("/health/live").status_code == 200

    monkeypatch.setattr(settings, "service_token", "")
    assert client.get("/health/ready").status_code == 200


def test_the_real_provider_gate_stays_closed_by_default() -> None:
    assert make().real_providers_allowed is False
    assert make(real_providers_enabled="yes").real_providers_allowed is False
    assert make(real_providers_enabled=" TRUE ").real_providers_allowed is True
