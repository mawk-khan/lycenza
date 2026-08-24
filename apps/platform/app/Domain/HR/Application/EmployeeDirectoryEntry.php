<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.8 -- one Employee's row in the Employee Directory. This is
 * a deliberate DISCLOSURE PROJECTION, not a convenience wrapper around
 * `Employee` or any of its relations -- it is never constructed from
 * `$employee->toArray()`/`Employee::with([...])->get()`, and it never
 * holds a loaded Eloquent model that could later serialize an
 * unexpected relationship. Every property here is an explicit scalar
 * or null, and the exact property list below IS the entire disclosure
 * contract (docs/modules/HR.md "Employee Directory (8A.8,
 * implemented)"): if a field is not listed here, it is structurally
 * impossible for it to leak through this class, regardless of what
 * `EmployeeDirectoryService` internally queries to build one.
 *
 * Deliberately excludes (docs/modules/HR.md privacy classification
 * matrix -- Restricted/Highly Sensitive, never Directory-tier):
 * date_of_birth, nationality, marital_status, preferred_language,
 * personal_email, personal_phone, alternate_phone, any address,
 * emergency contacts, qualification/experience/certification detail,
 * EmployeeDocument metadata (including document count), government
 * identifiers, and any `Employee.record_status`/lifecycle-status field
 * -- HR.md's own Directory-tier row lists only name/employee number/
 * work contact/position/department/campus/manager, never a status
 * dimension, so none is exposed here (record_status is used
 * server-side only, as a query FILTER, never as a returned field).
 *
 * No work-contact fields (`work_email`/`work_phone`) are included --
 * no such columns exist on `Employee` yet, and personal contact info
 * from 8A.2 (`personal_email`/`personal_phone`/`alternate_phone`) is
 * Restricted and must never stand in for a work-contact field that
 * does not yet exist. This is a documented gap, not a workaround.
 */
final class EmployeeDirectoryEntry
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $employeeNumber,
        public readonly string $displayName,
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

    /**
     * @return array{
     *     employee_id: string,
     *     employee_number: string,
     *     display_name: string,
     *     position_id: string|null,
     *     position_name: string|null,
     *     department_id: string|null,
     *     department_name: string|null,
     *     campus_id: string|null,
     *     campus_name: string|null,
     *     manager_employee_id: string|null,
     *     manager_employee_number: string|null,
     *     manager_display_name: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employee_number' => $this->employeeNumber,
            'display_name' => $this->displayName,
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
