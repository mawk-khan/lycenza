<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmploymentRecord` row projected into the Profile
 * Workspace's "Employment History" section. Every EmploymentRecord the
 * Employee has ever had is represented, including historical
 * (rehire-superseded) ones -- history is never erased or merged into
 * one artificial interval. Ordered `starts_on DESC, id`.
 */
final class EmployeeProfileEmploymentEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $employmentType,
        public readonly string $startsOn,
        public readonly ?string $endsOn,
        public readonly ?string $probationEndsOn,
        public readonly string $status,
        public readonly bool $isCurrent,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'employment_type' => $this->employmentType,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'probation_ends_on' => $this->probationEndsOn,
            'status' => $this->status,
            'is_current' => $this->isCurrent,
        ];
    }
}
