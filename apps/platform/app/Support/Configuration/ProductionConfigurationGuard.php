<?php

namespace App\Support\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Encryption\Encrypter;

/**
 * Phase 0O.1 (docs/architecture/PHASE-0O-READINESS.md §10): the fail-closed
 * production configuration check. App\Providers\AppServiceProvider::register()
 * runs it on EVERY boot when the application environment is `production`
 * (the framework's own signal -- no second notion of "production"), so an
 * unsafe configuration cannot serve web traffic or start a queue worker,
 * the scheduler or any artisan command. It reads resolved config (never
 * env()), so it behaves the same with `config:cache`.
 *
 * Refuses: debug on; a missing or unusable application key; a session
 * cookie that is not Secure; a missing, blank or committed placeholder AI
 * context signing key; the public development AI Gateway service token.
 * An absent service token is allowed (the AI Gateway is optional, CLAUDE.md
 * rule 56) -- only the known development value is refused.
 *
 * The exception names violation codes only, never a value.
 */
final class ProductionConfigurationGuard
{
    /** The committed development / test values (`.env.example`, `.ddev`, phpunit.xml). */
    public const PLACEHOLDER_SIGNING_KEYS = [
        'dev-local-only-context-signing-key-change-me',
        'test-only-context-signing-key',
    ];

    /** The public local development service token (`.env.example`, `.ddev`, the AI Gateway's former default). */
    public const DEVELOPMENT_SERVICE_TOKEN = 'dev-local-only-token';

    public function __construct(private readonly Repository $config) {}

    /**
     * @return list<string> violation codes; empty when the configuration is safe
     */
    public function violations(): array
    {
        $violations = [];

        if ((bool) $this->config->get('app.debug') !== false) {
            $violations[] = 'app_debug_enabled';
        }

        $violations = [...$violations, ...$this->appKeyViolations()];

        if ($this->config->get('session.secure') !== true) {
            $violations[] = 'session_cookie_not_secure';
        }

        $signingKey = $this->config->get('services.ai_gateway.context_signing_key');
        if (! is_string($signingKey) || trim($signingKey) === '') {
            $violations[] = 'ai_context_signing_key_missing';
        } elseif (in_array($signingKey, self::PLACEHOLDER_SIGNING_KEYS, true)) {
            $violations[] = 'ai_context_signing_key_placeholder';
        }

        if ($this->config->get('services.ai_gateway.service_token') === self::DEVELOPMENT_SERVICE_TOKEN) {
            $violations[] = 'ai_service_token_development_value';
        }

        return $violations;
    }

    public function assertSafe(): void
    {
        $violations = $this->violations();

        if ($violations !== []) {
            throw new ProductionConfigurationException($violations);
        }
    }

    /**
     * @return list<string>
     */
    private function appKeyViolations(): array
    {
        $key = $this->config->get('app.key');

        if (! is_string($key) || trim($key) === '') {
            return ['app_key_missing'];
        }

        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($raw) && Encrypter::supported($raw, (string) $this->config->get('app.cipher'))
            ? []
            : ['app_key_invalid'];
    }
}
