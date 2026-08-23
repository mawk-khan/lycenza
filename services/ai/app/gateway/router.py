from app.providers.base import ModelProvider
from app.providers.null_provider import NullProvider


class ModelRouter:
    """Model Provider Registry + Router.

    Chooses which registered `ModelProvider` handles a request. Selection
    may later consider agent, capability, cost, data residency, or a
    tenant's configured provider — none of that policy leaks into
    `app.providers.base.ModelProvider` implementations themselves.
    """

    def __init__(self) -> None:
        self._providers: dict[str, ModelProvider] = {"null": NullProvider()}

    def register(self, provider: ModelProvider) -> None:
        self._providers[provider.name] = provider

    def resolve(self, name: str | None = None) -> ModelProvider:
        key = name or "null"
        if key not in self._providers:
            raise ValueError(f"Unknown model provider: {key!r}")
        return self._providers[key]


model_router = ModelRouter()
