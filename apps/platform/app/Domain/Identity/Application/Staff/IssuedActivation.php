<?php

namespace App\Domain\Identity\Application\Staff;

use App\Models\User;
use Carbon\CarbonInterface;
use SensitiveParameter;

/**
 * Phase 0O.12B (ADR 0059 section 5): the result of issuing a bootstrap
 * activation credential -- the ONE place the link (secret in its fragment)
 * exists in plaintext. The console prints it once; nothing stores, logs or
 * audits it.
 */
final class IssuedActivation
{
    public function __construct(
        public readonly User $user,
        #[SensitiveParameter] public readonly string $link,
        public readonly CarbonInterface $expiresAt,
        public readonly bool $reissued,
    ) {}
}
