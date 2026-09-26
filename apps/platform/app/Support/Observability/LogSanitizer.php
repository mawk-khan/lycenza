<?php

namespace App\Support\Observability;

/**
 * Phase 0C.4 section 37, made central in Phase 0O.5A (ADR 0051 §6): the
 * one sanitizer every log record passes through (SafeLogProcessor and
 * StructuredJsonFormatter call it; callers never have to). Callers are
 * still expected to pass only minimal, already-safe metadata -- this is
 * the backstop, not the primary control.
 *
 * Keys: matched by WORD SEGMENT after normalising snake/kebab/camel case
 * (`webhookSecret`, `WEBHOOK_SECRET`, `x-schoolos-signature` all match),
 * so a sensitive word anywhere in a key redacts it, but a short fragment
 * inside an unrelated word does not (`otp` never matches `footprint`,
 * `token` never matches the count `input_tokens`).
 *
 * Values: secret-shaped strings are scrubbed wherever they appear --
 * including messages -- with a small, bounded set of patterns (bearer
 * credentials, the application's own `lyc_pat_`/`lyc_pk_` credentials,
 * `base64:` keys, URL userinfo, the committed development tokens). It is
 * deliberately not a universal secret detector.
 */
class LogSanitizer
{
    public const REDACTED = '[redacted]';

    /**
     * Word sequences whose presence in a key redacts the value, whatever
     * its type.
     *
     * @var list<string>
     */
    private const SENSITIVE_WORDS = [
        'password', 'passwd', 'passphrase', 'secret', 'secrets', 'authorization', 'cookie', 'cookies',
        'credential', 'credentials', 'private_key', 'api_key', 'apikey', 'access_key',
        'recovery_code', 'recovery_codes', 'otp', 'totp', 'mfa_secret', 'app_key', 'previous_keys',
        'signing_key', 'hmac_key', 'service_token', 'dsn', 'prompt', 'completion', 'context_token',
    ];

    /**
     * Words that redact string/array values but leave numbers and booleans
     * (counts such as `token_count` or flags such as `session_ended`
     * stay readable).
     *
     * @var list<string>
     */
    private const SENSITIVE_STRING_WORDS = [
        'token', 'signature', 'session', 'hash', 'body', 'content', 'contents', 'payload',
    ];

    /** @var array<string, string> pattern => replacement */
    private const VALUE_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._~+\/|=-]+/i' => 'Bearer '.self::REDACTED,
        '/\blyc_(pat|pk)_[A-Za-z0-9._|-]+/' => 'lyc_$1_'.self::REDACTED,
        '/\bbase64:[A-Za-z0-9+\/=]{16,}/' => 'base64:'.self::REDACTED,
        '/\b([a-z][a-z0-9+.-]*:\/\/)[^\s\/:@]+:[^\s\/@]+@/i' => '$1'.self::REDACTED.'@',
        '/dev-local-only-(?:token|context-signing-key-change-me)/' => self::REDACTED,
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->redacts($key, $value)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->sanitize($value),
                is_string($value) => $this->sanitizeString($value),
                default => $value,
            };
        }

        return $result;
    }

    public function sanitizeString(string $value): string
    {
        return (string) preg_replace(array_keys(self::VALUE_PATTERNS), array_values(self::VALUE_PATTERNS), $value);
    }

    public function isSensitiveKey(string $key): bool
    {
        return $this->matches($key, self::SENSITIVE_WORDS) || $this->matches($key, self::SENSITIVE_STRING_WORDS);
    }

    private function redacts(string $key, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if ($this->matches($key, self::SENSITIVE_WORDS)) {
            return true;
        }

        return ! is_int($value) && ! is_float($value) && ! is_bool($value) && $this->matches($key, self::SENSITIVE_STRING_WORDS);
    }

    /**
     * @param  list<string>  $words
     */
    private function matches(string $key, array $words): bool
    {
        $normalized = '_'.strtolower((string) preg_replace(['/([a-z0-9])([A-Z])/', '/[^A-Za-z0-9]+/'], ['$1_$2', '_'], $key)).'_';

        foreach ($words as $word) {
            if (str_contains($normalized, '_'.$word.'_')) {
                return true;
            }
        }

        return false;
    }
}
