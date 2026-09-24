from app.providers.base import CompletionRequest, CompletionResult, ModelProvider


class NullProvider(ModelProvider):
    """Deterministic, offline stand-in provider.

    Used for local development, tests, and CI so Phase 0A never requires
    real model provider credentials or makes external network calls. Not
    registered for any real agent traffic once a real provider exists.
    """

    name = "null"
    external = False

    async def complete(self, request: CompletionRequest) -> CompletionResult:
        echoed = request.prompt[: request.max_tokens]
        return CompletionResult(
            text=f"[null-provider stub response] {echoed}",
            provider=self.name,
            model="null-echo-1",
            input_tokens=len(request.prompt.split()),
            output_tokens=len(echoed.split()),
        )
