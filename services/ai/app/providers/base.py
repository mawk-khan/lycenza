from abc import ABC, abstractmethod
from dataclasses import dataclass


@dataclass(frozen=True)
class CompletionRequest:
    prompt: str
    max_tokens: int = 512
    temperature: float = 0.2


@dataclass(frozen=True)
class CompletionResult:
    text: str
    provider: str
    model: str
    input_tokens: int
    output_tokens: int


class ModelProvider(ABC):
    """Provider-independent contract every LLM backend must implement.

    No file outside `app/providers/` may know which concrete provider
    (Anthropic, OpenAI, Google, Azure OpenAI, a local model, ...) is in
    use. Domain/agent code depends only on this interface, resolved
    through `app.gateway.router.ModelRouter`.
    See docs/architecture/adr/0013-ai-gateway-architecture.md.
    """

    name: str

    # Gap G4: every provider is treated as EXTERNAL (it could send data off
    # the platform) unless it explicitly declares otherwise. Only the
    # offline NullProvider does. A provider that forgets to set this is
    # therefore gated by ModelRouter, never silently allowed.
    external: bool = True

    @abstractmethod
    async def complete(self, request: CompletionRequest) -> CompletionResult: ...
