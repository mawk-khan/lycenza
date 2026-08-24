<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmployeeAssignment` row (across ALL of the
 * Employee's EmploymentRecords, past and present) projected into the
 * Profile Workspace's "Assignment History" section. Unlike
 * `EmployeeDirectoryEntry` (8A.8), secondary Assignments are never
 * hidden here -- the Profile Workspace is history, not a one-row-per-
 * Employee disclosure boundary. `employmentRecordId` is the explicit
 * association back to `EmployeeProfileEmploymentEntry`, rather than
 * nesting Assignments inside Employment entries. Ordered `starts_on
 * DESC, id`. Deliberately carries no manager information -- manager
 * disclosure lives only on `EmployeeProfileSummary` (current primary
 * Assignment, one hop), never reconstructed per historical row.
 */
final class EmployeeProfileAssignmentEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $employmentRecordId,
        public readonly bool $isPrimary,
        public readonly string $startsOn,
        public readonly ?string $endsOn,
        public readonly bool $isCurrent,
        public readonly ?string $positionId,
        public readonly ?string $positionName,
        public readonly ?string $departmentId,
        public readonly ?string $departmentName,
        public readonly ?string $campusId,
        public readonly ?string $campusName,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'employment_record_id' => $this->employmentRecordId,
            'is_primary' => $this->isPrimary,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'is_current' => $this->isCurrent,
            'position_id' => $this->positionId,
            'position_name' => $this->positionName,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'campus_id' => $this->campusId,
            'campus_name' => $this->campusName,
        ];
    }
}
