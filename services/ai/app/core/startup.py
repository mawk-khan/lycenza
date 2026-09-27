"""Phase 0O.1 / 0O.7A: the AI Gateway's fail-closed configuration check.

The service refuses to start (app.main calls assert_safe_configuration()
at import, before any route exists) and reports itself not ready
(app.core.health) when its ADR 0053 service-authentication configuration is
unusable:

- its own `ai-gateway` signing key is missing or malformed, is a committed
  development key outside local/testing, or is older than 90 days;
- the `platform` verification ring is missing, malformed, holds a
  development key outside local/testing, or breaks the ring rules (1-2 keys,
  one steady key, a transitional key at most 24 h ahead);
- the retired shared service token is still configured (ANY environment:
  there is no fallback to it);
- the Laravel internal URL is not https outside local/testing.

"Local/testing" means ENVIRONMENT is exactly ``local`` or ``testing``
(case-insensitive); anything else, including an unset value (the default
is ``production``), is treated as a real deployment.

Violations are reported as fixed codes only -- never a configured value.
"""

from datetime import UTC, datetime

from app.core.config import Settings
from app.core.service_auth import (
    MAX_KEY_AGE_DAYS,
    ServiceKeyConfigError,
    is_development_key,
    parse_signing_key,
    parse_verification_ring,
)

LOCAL_ENVIRONMENTS = frozenset({"local", "testing"})


class UnsafeConfigurationError(RuntimeError):
    def __init__(self, violations: list[str]) -> None:
        self.violations = violations
        super().__init__(
            "Refusing to start: unsafe AI Gateway configuration (" + ", ".join(violations) + ")."
        )


def is_local_environment(settings: Settings) -> bool:
    return settings.environment.strip().lower() in LOCAL_ENVIRONMENTS


def configuration_violations(settings: Settings, now: datetime | None = None) -> list[str]:
    violations: list[str] = []
    local = is_local_environment(settings)
    now = now or datetime.now(UTC)

    if settings.service_token.strip():
        violations.append("legacy_service_token_configured")

    if not settings.service_signing_key.strip():
        violations.append("service_signing_key_missing")
    else:
        try:
            key = parse_signing_key(settings.service_signing_key, now)
            if not local and is_development_key(key.kid, key.public):
                violations.append("service_signing_key_development")
            elif not local and key.age_days(now) > MAX_KEY_AGE_DAYS:
                violations.append("service_signing_key_expired")
        except ServiceKeyConfigError as exc:
            violations.append("service_" + exc.code)

    if not settings.platform_verification_keys.strip():
        violations.append("verification_keys_missing")
    else:
        try:
            ring = parse_verification_ring(settings.platform_verification_keys, now)
            if not local and any(is_development_key(k.kid, k.public) for k in ring):
                violations.append("verification_keys_development")
        except ServiceKeyConfigError as exc:
            violations.append(exc.code)

    if not local and not settings.erp_contract_base_url.startswith("https://"):
        violations.append("erp_contract_url_not_https")

    return violations


def assert_safe_configuration(settings: Settings) -> None:
    violations = configuration_violations(settings)
    if violations:
        raise UnsafeConfigurationError(violations)
