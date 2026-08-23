from unittest.mock import AsyncMock, patch

import httpx
import pytest

from app.audit import laravel_audit


@pytest.mark.asyncio
async def test_write_through_returns_the_durable_audit_record_on_success() -> None:
    fake_response = httpx.Response(
        200,
        json={"audit": {"id": "audit-1", "recordedAt": "2026-08-22T00:00:00Z"}},
        request=httpx.Request("POST", "http://platform.test/audit"),
    )

    with patch("httpx.AsyncClient.post", new=AsyncMock(return_value=fake_response)):
        result = await laravel_audit.write_through(
            school_id="school-a",
            agent="phase0b-proof-agent",
            tool="school.echo",
            action="tool.invoke",
            context_token="a-context-token",
        )

    assert result == {"id": "audit-1", "recordedAt": "2026-08-22T00:00:00Z"}


@pytest.mark.asyncio
async def test_write_through_fails_safely_when_laravel_rejects_the_request() -> None:
    """A denied write-back (e.g. an invalid/expired context token) must
    never raise -- the AI Gateway action it describes has already
    completed and must not fail the caller over an audit-plumbing
    problem (section 58/69)."""
    fake_response = httpx.Response(
        401,
        json={"error": {"message": "Invalid or expired AI context token."}},
        request=httpx.Request("POST", "http://platform.test/audit"),
    )

    with patch("httpx.AsyncClient.post", new=AsyncMock(return_value=fake_response)):
        result = await laravel_audit.write_through(
            school_id="school-a",
            agent="phase0b-proof-agent",
            tool="school.echo",
            action="tool.invoke",
            context_token="expired-or-tampered",
        )

    assert result is None


@pytest.mark.asyncio
async def test_write_through_fails_safely_when_laravel_is_unreachable() -> None:
    with patch(
        "httpx.AsyncClient.post",
        new=AsyncMock(side_effect=httpx.ConnectError("connection refused")),
    ):
        result = await laravel_audit.write_through(
            school_id="school-a",
            agent="phase0b-proof-agent",
            tool="school.echo",
            action="tool.invoke",
            context_token="a-context-token",
        )

    assert result is None
