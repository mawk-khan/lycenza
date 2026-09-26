from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Runtime configuration for the AI Gateway service.

    Real provider credentials are never required to run the health check
    or the unit test suite. Phase 0O.1: the service token has NO default
    and the environment defaults to "production" -- an unset value fails
    closed (app.core.startup refuses to start) instead of silently
    falling back to the public development token. Local development sets
    both explicitly (.env.example, docker-compose's env_file).
    """

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    service_name: str = "school-os-ai-gateway"
    environment: str = "production"

    # Shared secret the platform (Laravel) and this service use to
    # authenticate service-to-service calls in both directions.
    # See docs/ai/AI-SECURITY.md. Required: never defaulted (Phase 0O.1).
    service_token: str = ""

    # Base URL of the Laravel application's internal "ERP tool" contracts
    # that AI tools are allowed to call. AI code must never reach any
    # other Laravel route, and must never touch the database directly.
    erp_contract_base_url: str = "http://platform:8000/api/internal/ai"

    # Gap G4 (docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md): the
    # off-by-default switch for any EXTERNAL model provider. Kept as raw
    # text so that a missing, empty or malformed value can never switch it
    # on or stop the service booting: only the exact word "true" (any case)
    # enables it. Turning it on is only permitted after the recorded
    # legal/compliance approvals that gate requires -- and no external
    # provider exists in this codebase either way.
    real_providers_enabled: str = ""

    # Phase 0O.5A (ADR 0051 §5): `json` (production) or `text` for a local
    # terminal. Structured JSON is the default everywhere.
    log_format: str = "json"

    @property
    def real_providers_allowed(self) -> bool:
        return self.real_providers_enabled.strip().lower() == "true"


settings = Settings()
