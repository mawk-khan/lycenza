<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- the Employee Profile Workspace's Restricted-tier
 * personal-contact section (`employee_personal_details.personal_email`/
 * `personal_phone`/`alternate_phone`). This is NOT work contact --
 * School OS has no `work_email`/`work_phone` columns anywhere yet
 * (docs/modules/HR.md "Employee Directory (8A.8, implemented)"'s
 * documented work-contact gap). Being visible in the Restricted HR
 * Profile Workspace does not change that gap or promote these fields
 * to Directory tier -- `EmployeeDirectoryEntry` still excludes all
 * three. Null when the Employee has no `EmployeePersonalDetail` row.
 */
final class EmployeeProfileContact
{
    public function __construct(
        public readonly ?string $personalEmail,
        public readonly ?string $personalPhone,
        public readonly ?string $alternatePhone,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'personal_email' => $this->personalEmail,
            'personal_phone' => $this->personalPhone,
            'alternate_phone' => $this->alternatePhone,
        ];
    }
}
