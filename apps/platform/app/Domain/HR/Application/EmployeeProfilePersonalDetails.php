<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- the Restricted-tier "Personal Details" section
 * (date_of_birth/nationality/marital_status/preferred_language only --
 * contact fields live in `EmployeeProfileContact` instead, a separate
 * section per docs/modules/HR.md's section model). Null when the
 * Employee has no `EmployeePersonalDetail` row at all -- absence is
 * not an error.
 */
final class EmployeeProfilePersonalDetails
{
    public function __construct(
        public readonly ?string $dateOfBirth,
        public readonly ?string $nationality,
        public readonly ?string $maritalStatus,
        public readonly ?string $preferredLanguage,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date_of_birth' => $this->dateOfBirth,
            'nationality' => $this->nationality,
            'marital_status' => $this->maritalStatus,
            'preferred_language' => $this->preferredLanguage,
        ];
    }
}
