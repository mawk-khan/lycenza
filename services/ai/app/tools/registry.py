from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from typing import Any


class ToolAuthorizationError(RuntimeError):
    """Raised when an agent invokes a tool without a required capability."""


class ToolExecutionError(RuntimeError):
    """Raised when a tool's handler fails to execute (e.g. the Laravel
    AI-tool-contract call it relays to returns a non-2xx response)."""


@dataclass(frozen=True)
class ToolDefinition:
    """One explicitly exposed ERP action an AI agent may request.

    A tool never touches the database directly — its `handler` calls out
    to an authenticated Laravel "AI tool contract" endpoint that runs the
    normal domain service, authorization, and audit path. See
    docs/ai/AI-SECURITY.md for the full
    Agent -> Capability -> Tool -> Authorization -> Policy -> Approval ->
    Domain service -> Audit chain this registry is one link in.
    """

    name: str
    required_capability: str
    description: str
    handler: Callable[[dict[str, Any]], Awaitable[dict[str, Any]]]


class ToolRegistry:
    def __init__(self) -> None:
        self._tools: dict[str, ToolDefinition] = {}

    def register(self, tool: ToolDefinition) -> None:
        self._tools[tool.name] = tool

    def get(self, name: str) -> ToolDefinition:
        if name not in self._tools:
            raise KeyError(f"Unknown tool: {name!r}")
        return self._tools[name]

    async def invoke(
        self, tool_name: str, granted_capabilities: set[str], payload: dict[str, Any]
    ) -> dict[str, Any]:
        tool = self.get(tool_name)
        if tool.required_capability not in granted_capabilities:
            raise ToolAuthorizationError(
                f"Tool {tool_name!r} requires capability "
                f"{tool.required_capability!r}, which was not granted."
            )
        return await tool.handler(payload)


tool_registry = ToolRegistry()
