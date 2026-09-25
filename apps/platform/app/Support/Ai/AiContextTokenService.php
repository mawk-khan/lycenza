<?php

namespace App\Support\Ai;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Signs and verifies short-lived, tamper-evident "AI context tokens".
 * See docs/architecture/adr/0023-ai-context-token-strengthening.md.
 *
 * Laravel mints a token binding (school_id, actor_id, granted
 * capabilities, expiry) BEFORE calling the AI Gateway. The AI Gateway
 * never holds the signing key -- it only relays the opaque token back
 * to Laravel's AI-tool-contract endpoint unchanged. Because only
 * Laravel ever signs, the AI Gateway (even if fully compromised) cannot
 * fabricate a School B context for an actor only entitled to School A
 * -- it can, at most, replay a token Laravel itself already issued.
 *
 * This is intentionally NOT a general-purpose JWT implementation --
 * it's a minimal HMAC-signed envelope narrowly scoped to this one
 * internal boundary. No external library, no algorithm negotiation
 * (avoids the classic "alg confusion" JWT footgun entirely).
 */
class AiContextTokenService
{
    private const TTL_SECONDS = 60;

    /**
     * Phase 0O.1: a missing or blank key is accepted here (so an app that
     * never uses AI still boots outside production) but refused at the
     * first issue() or verify() -- never used as empty HMAC key material.
     * Production refuses to boot without it
     * (App\Support\Configuration\ProductionConfigurationGuard).
     */
    public function __construct(private readonly ?string $signingKey) {}

    /**
     * @param  array<int, string>  $grantedCapabilities
     */
    public function issue(School $school, User $actor, array $grantedCapabilities, ?string $requestId = null): string
    {
        $payload = [
            'school_id' => $school->id,
            'actor_id' => $actor->id,
            'capabilities' => array_values($grantedCapabilities),
            'request_id' => $requestId,
            'jti' => (string) Str::uuid(),
            'iat' => now()->timestamp,
            'exp' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ];

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadB64 = self::base64UrlEncode($payloadJson);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $this->key(), true));

        return "{$payloadB64}.{$signature}";
    }

    /**
     * @return AiContextClaims|null null when the token is missing,
     *                              malformed, has an invalid signature, or has expired.
     */
    public function verify(?string $token): ?AiContextClaims
    {
        $key = $this->key();

        if ($token === null || ! str_contains($token, '.')) {
            return null;
        }

        [$payloadB64, $signature] = explode('.', $token, 2);

        $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $key, true));

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        $payload = json_decode($payloadJson, true);

        if (! is_array($payload) || ! isset($payload['exp'], $payload['school_id'], $payload['actor_id'])) {
            return null;
        }

        if ($payload['exp'] < now()->timestamp) {
            return null;
        }

        return new AiContextClaims(
            schoolId: $payload['school_id'],
            actorId: $payload['actor_id'],
            capabilities: $payload['capabilities'] ?? [],
            requestId: $payload['request_id'] ?? null,
            issuedAt: $payload['iat'] ?? null,
            expiresAt: $payload['exp'],
        );
    }

    private function key(): string
    {
        if ($this->signingKey === null || trim($this->signingKey) === '') {
            throw new AiContextSigningKeyNotConfiguredException;
        }

        return $this->signingKey;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
