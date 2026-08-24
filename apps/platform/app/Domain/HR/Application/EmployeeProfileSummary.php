<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- the Employee Profile Workspace's identity/current-state
 * section. Unlike `EmployeeDirectoryEntry` (8A.8, Directory-tier only),
 * this is the Restricted HR-internal view -- it may include
 * `employeeRecordStatus` (Employee's own lifecycle) and
 * `currentEmploymentStatus` (the current EmploymentRecord's own
 * lifecycle), kept as two explicitly separate, explicitly named
 * fields rather than one collapsed "status" (docs/modules/HR.md's
 * state responsibility matrix: Employee.record_status,
 * EmploymentRecord.status, Assignment dates, and User/account status
 * are four distinct dimensions that must never be merged).
 *
 * Deliberately excludes any User account/login/security state --
 * `userLinked` is the only User-adjacent fact exposed (whether
 * `employees.user_id` is set), never account status, role, or
 * credentials. User != Employee remains non-negotiable.
 */
final class EmployeeProfileSummary
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $employeeNumber,
        public readonly string $displayName,
        public readonly string $employeeRecordStatus,
        public readonly bool $userLinked,
        public readonly ?string $currentEmploymentStatus,
        public readonly ?string $positionId,
        public readonly ?string $positionName,
        public readonly ?string $departmentId,
        public readonly ?string $departmentName,
        public readonly ?string $campusId,
        public readonly ?string $campusName,
        public readonly ?string $managerEmployeeId,
        public readonly ?string $managerEmployeeNumber,
        public readonly ?string $managerDisplayName,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employee_number' => $this->employeeNumber,
            'display_name' => $this->displayName,
            'employee_record_status' => $this->employeeRecordStatus,
            'user_linked' => $this->userLinked,
            'current_employment_status' => $this->currentEmploymentStatus,
            'position_id' => $this->positionId,
            'position_name' => $this->positionName,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'campus_id' => $this->campusId,
            'campus_name' => $this->campusName,
            'manager_employee_id' => $this->managerEmployeeId,
            'manager_employee_number' => $this->managerEmployeeNumber,
            'manager_display_name' => $this->managerDisplayName,
        ];
    }
}
