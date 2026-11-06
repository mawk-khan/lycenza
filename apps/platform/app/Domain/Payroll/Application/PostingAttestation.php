<?php

namespace App\Domain\Payroll\Application;

/**
 * HR.R1-C4: what the poster submitted: the evidence they reviewed and their
 * explicit acceptance of it. Never defaulted and never implied by pressing Post.
 */
final class PostingAttestation
{
    public function __construct(
        public readonly string $evidenceVersion,
        public readonly bool $accepted,
    ) {}
}
