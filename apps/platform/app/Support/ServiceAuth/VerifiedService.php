<?php

namespace App\Support\ServiceAuth;

/** The authenticated calling service. Carries no School, actor or capability. */
final class VerifiedService
{
    public function __construct(
        public readonly string $service,
        public readonly string $kid,
        public readonly string $jti,
        public readonly int $expiresAt,
    ) {}
}
