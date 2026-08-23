<?php

namespace App\Support\Observability;

/**
 * Phase 0C.4 section 37: a centralized backstop against sensitive keys
 * reaching a log line or error report -- callers are still expected to
 * only pass explicit, minimal, already-safe metadata (see
 * App\Support\Observability\ErrorReporter's docblock); this exists so
 * that expectation is not the ONLY thing standing between a mistake and
 * a leaked secret. Recurses into nested arrays. Matching is
 * case-insensitive and matches on the key existing anywhere in a
 * snake_case/camelCase/PascalCase name (e.g. `webhookSecret`,
 * `WEBHOOK_SECRET`, and `previous_secret_encrypted` all redact),
 * deliberately broad rather than an exact-match allowlist that a new
 * field name could silently slip past.
 */
class LogSanitizer
{
    private const REDACTED = '[redacted]';

    /**
     * @var array<int, string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'authorization',
        'secret',
        'api_key',
        'apikey',
        'access_key',
        'private_key',
        'credential',
        'signature',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
