from dataclasses import dataclass, field


@dataclass(frozen=True)
class AgentDefinition:
    """A named AI agent and the fixed capability set it may request.

    Example (illustrative, not implemented): an "ai.fee_collection" agent
    might be granted `invoice.read`, `communication_history.read`,
    `reminder.draft`, and `message.request_delivery` — but never
    `invoice.write`, `fee.waive`, or `transaction.delete`. Capabilities
    are enforced by `app.tools.registry.ToolRegistry.invoke`, not by the
    agent's own code, so an agent cannot self-escalate.
    """

    name: str
    tenant_scoped: bool = True
    granted_capabilities: frozenset[str] = field(default_factory=frozenset)


class AgentRegistry:
    def __init__(self) -> None:
        self._agents: dict[str, AgentDefinition] = {}

    def register(self, agent: AgentDefinition) -> None:
        self._agents[agent.name] = agent

    def get(self, name: str) -> AgentDefinition:
        if name not in self._agents:
            raise KeyError(f"Unknown agent: {name!r}")
        return self._agents[name]


agent_registry = AgentRegistry()
