<?php

namespace App\Support\Ai;

/**
 * Verified claims from an AiContextTokenService context token. Only
 * ever constructed by AiContextTokenService::verify() after signature
 * and expiry checks pass.
 */
final class AiContextClaims
{
    /**
     * @param  array<int, string>  $capabilities
     */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $actorId,
        public readonly array $capabilities,
        public readonly ?string $requestId,
        public readonly ?int $issuedAt,
        public readonly int $expiresAt,
    ) {}

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
