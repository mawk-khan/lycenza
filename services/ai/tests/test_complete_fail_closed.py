"""Gaps G1-G4 (docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md): the
/v1/complete path fails closed. Laravel is mocked at the httpx level (as in
test_tool_invoke_endpoint.py); everything else runs for real. The canary
string is planted in prompts, provider output and provider/Laravel error
bodies, and must surface in no response, log record or audit payload."""

import importlib.metadata
import logging
import re
import socket
from pathlib import Path
from typing import Any
from unittest.mock import AsyncMock, patch

import httpx
import pytest
from fastapi.testclient import TestClient

from app.core.config import settings
from app.gateway.router import ModelRouter, ProviderNotAllowedError, model_router
from app.main import app
from app.providers.base import CompletionRequest, CompletionResult, ModelProvider
from app.providers.null_provider import NullProvider

client = TestClient(app)
AUTH = {"X-Service-Token": "dev-local-only-token"}
CANARY = "SHOULD-NOT-APPEAR-IN-LOG"
SCHOOL = "0199aaaa-0000-7000-8000-000000000001"
OTHER_SCHOOL = "0199bbbb-0000-7000-8000-000000000002"


def _response(status: int, body: dict[str, Any]) -> httpx.Response:
    return httpx.Response(status, json=body, request=httpx.Request("POST", "http://platform.test"))


class Laravel:
    """Fake Laravel internal AI contracts, keyed by path."""

    def __init__(
        self,
        authorize: httpx.Response | Exception | None = None,
        audit: httpx.Response | Exception | None = None,
    ) -> None:
        self.authorize = authorize or _response(
            200, {"authorization": {"schoolId": SCHOOL, "actorId": "actor-1", "requestId": "r-1"}}
        )
        self.audit = audit or _response(200, {"audit": {"id": "audit-1", "recordedAt": "now"}})
        self.calls: list[tuple[str, dict[str, Any]]] = []

    async def post(self, url: str, **kwargs: Any) -> httpx.Response:
        self.calls.append((url, kwargs.get("json") or {}))
        answer = self.authorize if url == "/completions/authorize" else self.audit
        if isinstance(answer, Exception):
            raise answer
        return answer

    def paths(self) -> list[str]:
        return [path for path, _ in self.calls]


def _complete(laravel: Laravel, **overrides: Any) -> httpx.Response:
    body = {"agent": "phase0b-proof-agent", "context_token": "tok", "prompt": f"hi {CANARY}"}
    body.update(overrides)
    with patch("httpx.AsyncClient.post", new=AsyncMock(side_effect=laravel.post)):
        return client.post("/v1/complete", json=body, headers=AUTH)


@pytest.fixture
def provider_spy():
    original = NullProvider.complete
    calls: list[str] = []

    async def spy(self: NullProvider, request: CompletionRequest) -> CompletionResult:
        calls.append(request.prompt)
        return await original(self, request)

    with patch.object(NullProvider, "complete", spy):
        yield calls


@pytest.fixture
def switch_off():
    with patch.object(settings, "real_providers_enabled", ""):
        yield


# --- G1: authorization before any provider call ---------------------------


def test_a_valid_null_provider_completion_is_authorized_audited_and_returned(provider_spy) -> None:
    laravel = Laravel()
    response = _complete(laravel)

    assert response.status_code == 200
    assert response.json()["provider"] == "null"
    assert len(provider_spy) == 1
    assert laravel.paths() == ["/completions/authorize", "/audit"]
    authorize = laravel.calls[0][1]
    assert authorize["capability"] == "school.settings.view"
    assert authorize["context_token"] == "tok"


@pytest.mark.parametrize(
    ("laravel_status", "code"),
    [(401, "context_invalid"), (403, "capability_denied"), (422, "context_mismatch")],
)
def test_laravel_refusals_stop_before_the_provider(provider_spy, laravel_status, code) -> None:
    # 401 covers missing/malformed/bad-signature/expired tokens, 403 a
    # token without the capability or a revoked capability, 422 a School
    # mismatch -- all decided by Laravel, which alone holds the key.
    laravel = Laravel(authorize=_response(laravel_status, {"error": {"code": CANARY}}))
    response = _complete(laravel)

    assert response.status_code == laravel_status
    assert response.json() == {"detail": code}
    assert provider_spy == []
    assert laravel.paths() == ["/completions/authorize"], "no audit when no model ran"


def test_authorization_unreachable_fails_closed(provider_spy) -> None:
    response = _complete(Laravel(authorize=httpx.ConnectError(CANARY)))
    assert response.status_code == 503
    assert response.json() == {"detail": "authorization_unavailable"}
    assert provider_spy == []


def test_a_request_school_that_disagrees_with_the_verified_context_is_refused(provider_spy) -> None:
    laravel = Laravel()
    response = _complete(laravel, school_id=OTHER_SCHOOL)
    assert response.status_code == 422
    assert provider_spy == []


def test_missing_token_and_unknown_or_unentitled_agents_never_reach_laravel(provider_spy) -> None:
    laravel = Laravel()
    missing = _complete(laravel, context_token=None)
    assert missing.status_code == 422
    assert CANARY not in missing.text, "validation errors never echo the input"

    unknown = _complete(laravel, agent="no-such-agent")
    assert unknown.status_code == 403
    assert unknown.json() == {"detail": "agent_not_allowed"}

    from app.agents.registry import AgentDefinition, agent_registry

    agent_registry.register(AgentDefinition(name="tools-only-agent"))
    no_capability = _complete(laravel, agent="tools-only-agent")
    assert no_capability.status_code == 403

    assert laravel.calls == []
    assert provider_spy == []


def test_the_service_token_alone_is_not_enough() -> None:
    laravel = Laravel()
    with patch("httpx.AsyncClient.post", new=AsyncMock(side_effect=laravel.post)):
        response = client.post(
            "/v1/complete", json={"agent": "phase0b-proof-agent", "prompt": "x"}, headers=AUTH
        )
    assert response.status_code == 422
    assert laravel.calls == []


# --- G2: durable audit, and its failure semantics --------------------------


def test_the_audit_record_carries_identifiers_and_numbers_only() -> None:
    laravel = Laravel()
    assert _complete(laravel).status_code == 200

    audits = [body for path, body in laravel.calls if path == "/audit"]
    assert len(audits) == 1, "exactly one durable audit per call"
    audit = audits[0]
    assert set(audit) == {
        "context_token",
        "school_id",
        "agent",
        "tool",
        "action",
        "provider",
        "model",
        "outcome",
        "latency_ms",
        "input_tokens",
        "output_tokens",
    }
    assert audit["school_id"] == SCHOOL, "the School comes from the verified context"
    assert (audit["action"], audit["provider"], audit["model"], audit["outcome"]) == (
        "model.complete",
        "null",
        "null-echo-1",
        "succeeded",
    )
    assert all(isinstance(audit[k], int) for k in ("latency_ms", "input_tokens", "output_tokens"))
    assert CANARY not in str(audit), "no prompt or output in the audit payload"


@pytest.mark.parametrize(
    "audit_failure", [_response(500, {"error": CANARY}), httpx.ConnectError(CANARY)]
)
def test_no_output_is_released_without_its_durable_audit(provider_spy, audit_failure) -> None:
    response = _complete(Laravel(audit=audit_failure))
    assert len(provider_spy) == 1
    assert response.status_code == 503
    assert response.json() == {"detail": "audit_unavailable"}
    assert CANARY not in response.text


class _FailingProvider(NullProvider):
    async def complete(self, request: CompletionRequest) -> CompletionResult:
        raise RuntimeError(f"provider exploded with body {CANARY}")


@pytest.mark.parametrize("audit_ok", [True, False])
def test_a_failed_provider_call_is_normalized_and_audited_as_failed(audit_ok) -> None:
    laravel = Laravel() if audit_ok else Laravel(audit=httpx.ConnectError("down"))
    with patch.dict(model_router._providers, {"null": _FailingProvider()}):
        response = _complete(laravel)

    assert response.status_code == 502
    assert response.json() == {"detail": "provider_error"}
    audit = [body for path, body in laravel.calls if path == "/audit"][0]
    assert audit["outcome"] == "provider_error"
    assert "model" not in audit and "input_tokens" not in audit
    assert CANARY not in str(audit)


# --- G3: nothing sensitive in logs or errors --------------------------------


def _all_log_text(records: list[logging.LogRecord]) -> str:
    return "\n".join(f"{r.getMessage()} {r.args} {r.__dict__} {r.exc_text or ''}" for r in records)


def test_canaries_never_reach_logs(caplog) -> None:
    caplog.set_level(logging.DEBUG)
    _complete(Laravel())
    _complete(Laravel(audit=_response(500, {"error": CANARY})))
    _complete(Laravel(audit=httpx.ConnectError(CANARY)))
    _complete(Laravel(authorize=_response(401, {"error": CANARY})))
    with patch.dict(model_router._providers, {"null": _FailingProvider()}):
        _complete(Laravel())

    assert caplog.records, "the flows above log safe metadata"
    assert CANARY not in _all_log_text(caplog.records)


def test_tool_relay_errors_never_carry_the_laravel_body(caplog) -> None:
    caplog.set_level(logging.DEBUG)
    failing = _response(500, {"error": CANARY})
    with patch("httpx.AsyncClient.post", new=AsyncMock(return_value=failing)):
        response = client.post(
            "/v1/tools/invoke",
            json={
                "school_id": SCHOOL,
                "agent": "phase0b-proof-agent",
                "tool": "school.echo",
                "context_token": "tok",
            },
            headers=AUTH,
        )
    assert response.status_code == 502
    assert CANARY not in response.text
    assert CANARY not in _all_log_text(caplog.records)


# --- G4: the off-by-default real-provider switch -----------------------------


class _ExternalFake(ModelProvider):
    name = "external-fake"

    async def complete(self, request: CompletionRequest) -> CompletionResult:  # pragma: no cover
        raise AssertionError("an external provider must never be called in this suite")


@pytest.mark.parametrize("value", ["", "false", "0", "1", "yes", "on", "enabled", " tru e", "no"])
def test_missing_or_invalid_switch_values_are_off(value) -> None:
    with patch.object(settings, "real_providers_enabled", value):
        assert settings.real_providers_allowed is False


def test_only_the_exact_word_true_turns_the_switch_on() -> None:
    for value in ("true", "TRUE", " True "):
        with patch.object(settings, "real_providers_enabled", value):
            assert settings.real_providers_allowed is True


def test_an_external_provider_can_be_neither_registered_nor_selected_while_off(switch_off) -> None:
    router = ModelRouter()
    with pytest.raises(ProviderNotAllowedError):
        router.register(_ExternalFake())

    # Even if one got in some other way, selection and startup refuse it.
    router._providers["external-fake"] = _ExternalFake()
    with pytest.raises(ProviderNotAllowedError):
        router.resolve("external-fake")
    with pytest.raises(ProviderNotAllowedError):
        router.assert_fail_closed()

    assert router.resolve().name == "null", "NullProvider never depends on the switch"


def test_providers_are_external_unless_they_say_otherwise() -> None:
    assert ModelProvider.external is True
    assert NullProvider.external is False


def test_selecting_an_external_provider_through_the_endpoint_is_refused(switch_off) -> None:
    laravel = Laravel()
    with patch.dict(model_router._providers, {"external-fake": _ExternalFake()}):
        response = _complete(laravel, provider="external-fake")
    assert response.status_code == 403
    assert response.json() == {"detail": "provider_not_allowed"}
    assert laravel.calls == [], "refused before any context or data moves"


def test_the_shipped_router_has_only_the_offline_provider() -> None:
    assert model_router.registered_names() == ["null"]
    model_router.assert_fail_closed()


# --- No SDK, no secret, no outbound call -------------------------------------

ALLOWED_RUNTIME = {"fastapi", "uvicorn", "pydantic", "pydantic-settings", "httpx"}
ALLOWED_DEV = {"pytest", "pytest-asyncio", "ruff", "mypy"}
PROVIDER_PACKAGES = {
    "anthropic",
    "openai",
    "google-generativeai",
    "google-genai",
    "google-cloud-aiplatform",
    "cohere",
    "mistralai",
    "groq",
    "litellm",
    "ollama",
    "replicate",
    "together",
    "langchain",
    "langchain-core",
    "langchain-openai",
    "langchain-anthropic",
    "llama-index",
    "huggingface-hub",
    "transformers",
    "boto3",
    "azure-ai-inference",
    "vertexai",
}
ROOT = Path(__file__).resolve().parent.parent


def _requirement_names(file: str) -> set[str]:
    names = set()
    for line in (ROOT / file).read_text().splitlines():
        line = line.strip()
        if line and not line.startswith(("#", "-r")):
            names.add(re.split(r"[\[=<>~! ]", line, maxsplit=1)[0].lower())
    return names


def test_requirements_contain_no_provider_sdk() -> None:
    assert _requirement_names("requirements.txt") <= ALLOWED_RUNTIME
    assert _requirement_names("requirements-dev.txt") <= ALLOWED_DEV


def test_no_provider_sdk_is_installed() -> None:
    installed = {dist.metadata["Name"].lower() for dist in importlib.metadata.distributions()}
    assert installed.isdisjoint(PROVIDER_PACKAGES), installed & PROVIDER_PACKAGES


def test_no_provider_credential_or_host_in_configuration() -> None:
    example = (ROOT / ".env.example").read_text()
    keys = {line.split("=", 1)[0] for line in example.splitlines() if "=" in line}
    assert keys == {"ENVIRONMENT", "SERVICE_TOKEN", "ERP_CONTRACT_BASE_URL"}
    assert set(type(settings).model_fields) == {
        "service_name",
        "environment",
        "service_token",
        "erp_contract_base_url",
        "real_providers_enabled",
    }


def test_a_completion_opens_no_socket_and_talks_only_to_laravel(monkeypatch) -> None:
    seen_urls: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen_urls.append(str(request.url))
        if request.url.path.endswith("/completions/authorize"):
            return httpx.Response(200, json={"authorization": {"schoolId": SCHOOL, "actorId": "a"}})
        return httpx.Response(200, json={"audit": {"id": "x", "recordedAt": "now"}})

    original_init = httpx.AsyncClient.__init__

    def init_with_mock_transport(self: httpx.AsyncClient, *args: Any, **kwargs: Any) -> None:
        kwargs["transport"] = httpx.MockTransport(handler)
        original_init(self, *args, **kwargs)

    def no_network(*args: Any, **kwargs: Any) -> None:
        raise AssertionError("outbound network connection attempted")

    monkeypatch.setattr(httpx.AsyncClient, "__init__", init_with_mock_transport)
    monkeypatch.setattr(socket.socket, "connect", no_network)

    response = client.post(
        "/v1/complete",
        json={"agent": "phase0b-proof-agent", "context_token": "tok", "prompt": "hi"},
        headers=AUTH,
    )

    assert response.status_code == 200
    base = settings.erp_contract_base_url.rstrip("/")
    assert seen_urls and all(url.startswith(base + "/") for url in seen_urls), seen_urls
