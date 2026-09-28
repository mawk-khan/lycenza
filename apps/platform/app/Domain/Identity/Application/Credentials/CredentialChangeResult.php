<?php

namespace App\Domain\Identity\Application\Credentials;

final class CredentialChangeResult
{
    public function __construct(
        public readonly int $revokedPersonalAccessTokens,
        public readonly bool $endedElevation,
    ) {}
}
