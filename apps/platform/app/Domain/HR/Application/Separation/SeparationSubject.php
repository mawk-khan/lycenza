<?php

namespace App\Domain\HR\Application\Separation;

/**
 * HR.12: what an obligation source is told about a separating employment --
 * ids and the School-local inclusive last day only, never personal data.
 */
final readonly class SeparationSubject
{
    public function __construct(
        public string $employmentRecordId,
        public string $employeeId,
        public ?string $userId,
        /** School-local Y-m-d, inclusive: the planned (or, once finalized, the final) last day. */
        public string $lastDay,
        public bool $finalized,
    ) {}
}
