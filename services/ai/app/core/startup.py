"""Phase 0O.1: the AI Gateway's fail-closed configuration check.

The service refuses to start (app.main calls assert_safe_configuration()
at import, before any route exists) and reports itself not ready
(app.core.health) when:

- the service token is absent or blank -- in ANY environment, because an
  empty expected token would let an empty X-Service-Token header through;
- the public development token (``dev-local-only-token``) is configured
  outside the approved local/testing environments.

"Local/testing" means ENVIRONMENT is exactly ``local`` or ``testing``
(case-insensitive); anything else, including an unset value (the default
is ``production``), is treated as a real deployment.

Violations are reported as fixed codes only -- never a configured value.
"""

from app.core.config import Settings

DEVELOPMENT_SERVICE_TOKEN = "dev-local-only-token"
LOCAL_ENVIRONMENTS = frozenset({"local", "testing"})


class UnsafeConfigurationError(RuntimeError):
    def __init__(self, violations: list[str]) -> None:
        self.violations = violations
        super().__init__(
            "Refusing to start: unsafe AI Gateway configuration (" + ", ".join(violations) + ")."
        )


def is_local_environment(settings: Settings) -> bool:
    return settings.environment.strip().lower() in LOCAL_ENVIRONMENTS


def configuration_violations(settings: Settings) -> list[str]:
    violations: list[str] = []

    if not settings.service_token.strip():
        violations.append("service_token_missing")
    elif settings.service_token == DEVELOPMENT_SERVICE_TOKEN and not is_local_environment(settings):
        violations.append("service_token_development_value")

    return violations


def assert_safe_configuration(settings: Settings) -> None:
    violations = configuration_violations(settings)
    if violations:
        raise UnsafeConfigurationError(violations)
