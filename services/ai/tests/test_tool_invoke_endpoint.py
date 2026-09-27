import json
from typing import Any
from unittest.mock import AsyncMock, patch

import httpx
from fastapi.testclient import TestClient

from app.main import app
from tests.service_keys import signed

client = TestClient(app)


def _with_traceparent(request: dict[str, Any], traceparent: str) -> dict[str, Any]:
    return {**request, "headers": {**request["headers"], "traceparent": traceparent}}


def test_invoke_requires_service_token() -> None:
    response = client.post(
        "/v1/tools/invoke",
        json={
            "school_id": "school-a",
            "agent": "phase0b-proof-agent",
            "tool": "school.echo",
            "context_token": "irrelevant",
        },
    )
    assert response.status_code == 401


def test_invoke_denied_when_agent_lacks_capability() -> None:
    # "phase0b-proof-agent" only holds "school.echo.invoke" -- asking
    # for a tool that requires a different capability must be denied at
    # this service's OWN gate, before any relay to Laravel is attempted.
    from app.tools.registry import ToolDefinition, tool_registry

    tool_registry.register(
        ToolDefinition(
            name="school.unrelated",
            required_capability="school.unrelated.invoke",
            description="test-only tool requiring a capability the demo agent lacks",
            handler=AsyncMock(return_value={}),
        )
    )

    response = client.post(
        "/v1/tools/invoke",
        **signed(
            "/v1/tools/invoke",
            {
                "school_id": "school-a",
                "agent": "phase0b-proof-agent",
                "tool": "school.unrelated",
                "context_token": "irrelevant",
            },
        ),
    )
    assert response.status_code == 403


def test_invoke_relays_context_token_to_laravel_and_records_audit() -> None:
    """Proves the full plumbing (agent capability check -> tool handler
    -> relay to Laravel with the context_token forwarded unchanged ->
    tool result, PLUS the separate durable-audit write-back call
    (section 58/69)) WITHOUT a live Laravel server -- httpx's outbound
    call is mocked at the transport level, but every other layer runs
    for real.
    """
    fake_response = httpx.Response(
        200,
        json={"result": {"schoolId": "school-a", "schoolName": "School A"}},
        request=httpx.Request("POST", "http://platform.test/tools/school-echo"),
    )

    with patch("httpx.AsyncClient.post", new=AsyncMock(return_value=fake_response)) as mocked_post:
        response = client.post(
            "/v1/tools/invoke",
            **signed(
                "/v1/tools/invoke",
                {
                    "school_id": "school-a",
                    "agent": "phase0b-proof-agent",
                    "tool": "school.echo",
                    "context_token": "signed-token-from-laravel",
                },
            ),
        )

    assert response.status_code == 200
    assert response.json()["result"]["schoolName"] == "School A"

    # Two outbound calls: the tool relay itself, then the durable-audit
    # write-back -- both forward the SAME context_token unchanged. This
    # service never inspects or alters it (ADR 0023: it holds no
    # signing key and cannot).
    assert mocked_post.call_count == 2
    for call in mocked_post.call_args_list:
        assert json.loads(call.kwargs["content"])["context_token"] == "signed-token-from-laravel"

    audit_call_args, audit_call_kwargs = mocked_post.call_args_list[1]
    assert audit_call_args[0] == "/audit"
    assert json.loads(audit_call_kwargs["content"])["action"] == "tool.invoke"


def test_invoke_propagates_the_inbound_trace_to_the_audit_write_back() -> None:
    """Phase 0C.4 section 43: the SAME trace-id Laravel started
    propagates through to the audit write-back call, with a fresh
    span-id (never the caller's own span-id adopted as this service's).
    """
    fake_response = httpx.Response(
        200,
        json={"result": {"schoolId": "school-a", "schoolName": "School A"}},
        request=httpx.Request("POST", "http://platform.test/tools/school-echo"),
    )
    inbound_trace_id = "4bf92f3577b34da6a3ce929d0e0e4736"
    inbound_span_id = "00f067aa0ba902b7"

    with patch("httpx.AsyncClient.post", new=AsyncMock(return_value=fake_response)) as mocked_post:
        client.post(
            "/v1/tools/invoke",
            **_with_traceparent(
                signed(
                    "/v1/tools/invoke",
                    {
                        "school_id": "school-a",
                        "agent": "phase0b-proof-agent",
                        "tool": "school.echo",
                        "context_token": "signed-token-from-laravel",
                    },
                ),
                f"00-{inbound_trace_id}-{inbound_span_id}-01",
            ),
        )

    _, audit_call_kwargs = mocked_post.call_args_list[1]
    audit_traceparent = audit_call_kwargs["headers"]["traceparent"]

    assert inbound_trace_id in audit_traceparent
    assert inbound_span_id not in audit_traceparent


def test_invoke_still_forwards_a_valid_trace_when_none_was_provided() -> None:
    fake_response = httpx.Response(
        200,
        json={"result": {"schoolId": "school-a", "schoolName": "School A"}},
        request=httpx.Request("POST", "http://platform.test/tools/school-echo"),
    )

    with patch("httpx.AsyncClient.post", new=AsyncMock(return_value=fake_response)) as mocked_post:
        client.post(
            "/v1/tools/invoke",
            **signed(
                "/v1/tools/invoke",
                {
                    "school_id": "school-a",
                    "agent": "phase0b-proof-agent",
                    "tool": "school.echo",
                    "context_token": "signed-token-from-laravel",
                },
            ),
        )

    _, audit_call_kwargs = mocked_post.call_args_list[1]
    assert "traceparent" in audit_call_kwargs["headers"]


def test_invoke_returns_bad_gateway_when_laravel_call_fails() -> None:
    fake_response = httpx.Response(
        401,
        text="Invalid or expired AI context token.",
        request=httpx.Request("POST", "http://platform.test/tools/school-echo"),
    )

    with patch("httpx.AsyncClient.post", new=AsyncMock(return_value=fake_response)):
        response = client.post(
            "/v1/tools/invoke",
            **signed(
                "/v1/tools/invoke",
                {
                    "school_id": "school-a",
                    "agent": "phase0b-proof-agent",
                    "tool": "school.echo",
                    "context_token": "expired-or-tampered",
                },
            ),
        )

    assert response.status_code == 502
