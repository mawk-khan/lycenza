import pytest

from app.tools.registry import ToolAuthorizationError, ToolDefinition, ToolRegistry


async def _draft_reminder(payload: dict) -> dict:
    return {"draft": f"Reminder for invoice {payload['invoice_id']}"}


@pytest.fixture
def registry() -> ToolRegistry:
    registry = ToolRegistry()
    registry.register(
        ToolDefinition(
            name="fee.draft_reminder",
            required_capability="communication.draft",
            description="Draft a fee reminder message for review.",
            handler=_draft_reminder,
        )
    )
    return registry


@pytest.mark.asyncio
async def test_tool_invocation_allowed_with_granted_capability(registry: ToolRegistry) -> None:
    result = await registry.invoke(
        "fee.draft_reminder",
        granted_capabilities={"communication.draft"},
        payload={"invoice_id": "INV-1"},
    )
    assert "INV-1" in result["draft"]


@pytest.mark.asyncio
async def test_tool_invocation_denied_without_capability(registry: ToolRegistry) -> None:
    with pytest.raises(ToolAuthorizationError):
        await registry.invoke(
            "fee.draft_reminder",
            granted_capabilities=set(),
            payload={"invoice_id": "INV-1"},
        )


@pytest.mark.asyncio
async def test_tool_invocation_denied_for_unrelated_capability(registry: ToolRegistry) -> None:
    with pytest.raises(ToolAuthorizationError):
        await registry.invoke(
            "fee.draft_reminder",
            granted_capabilities={"invoice.waive"},
            payload={"invoice_id": "INV-1"},
        )
