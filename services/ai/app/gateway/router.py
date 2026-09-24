from app.core.config import settings
from app.providers.base import ModelProvider
from app.providers.null_provider import NullProvider


class ProviderNotAllowedError(RuntimeError):
    """An external model provider was registered or selected while the
    real-provider switch is off (gap G4)."""


class UnknownProviderError(LookupError):
    """No provider of that name is registered."""


class ModelRouter:
    """Model Provider Registry + Router.

    Chooses which registered `ModelProvider` handles a request. Selection
    may later consider agent, capability, cost, data residency, or a
    tenant's configured provider — none of that policy leaks into
    `app.providers.base.ModelProvider` implementations themselves.

    Fail-closed (gap G4, docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md):
    an external provider can be neither registered nor selected while
    `settings.real_providers_allowed` is false, checked independently at
    each step, and the app refuses to start if one is registered anyway
    (`assert_fail_closed`). The offline NullProvider never depends on the
    switch.
    """

    def __init__(self) -> None:
        self._providers: dict[str, ModelProvider] = {"null": NullProvider()}

    def register(self, provider: ModelProvider) -> None:
        if provider.external and not settings.real_providers_allowed:
            raise ProviderNotAllowedError(
                f"External model provider {provider.name!r} cannot be registered: "
                "the real-provider switch is off."
            )
        self._providers[provider.name] = provider

    def resolve(self, name: str | None = None) -> ModelProvider:
        key = name or "null"
        if key not in self._providers:
            raise UnknownProviderError(f"Unknown model provider: {key!r}")
        provider = self._providers[key]
        if provider.external and not settings.real_providers_allowed:
            raise ProviderNotAllowedError(
                f"External model provider {key!r} cannot be used: the real-provider switch is off."
            )
        return provider

    def registered_names(self) -> list[str]:
        return sorted(self._providers)

    def assert_fail_closed(self) -> None:
        """Startup check: with the switch off, only offline providers exist."""
        if settings.real_providers_allowed:
            return
        external = [p.name for p in self._providers.values() if p.external]
        if external:
            raise ProviderNotAllowedError(
                f"External model provider(s) {external!r} registered while the "
                "real-provider switch is off."
            )


model_router = ModelRouter()
