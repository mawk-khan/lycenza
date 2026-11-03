<?php

namespace App\Domain\HR\Application\Workforce;

/**
 * HR.1A: one Employee's workforce placement ON A DATE, read from history --
 * the employment covering the date, its terms effective that day, and its
 * primary assignment covering the date (null fields where none). Ids and
 * closed codes only: never a name, contact, pay or profile field. Data,
 * never authorization.
 */
final readonly class WorkforcePlacement
{
    public function __construct(
        public string $employeeId,
        public string $date,
        public string $employmentRecordId,
        public string $employmentStatus,
        public ?string $employmentType,
        public ?string $staffType,
        public ?string $employeeCategoryId,
        public ?string $primaryAssignmentId,
        public ?string $campusId,
        public ?string $departmentId,
        public ?string $positionId,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'employeeId' => $this->employeeId,
            'date' => $this->date,
            'employmentRecordId' => $this->employmentRecordId,
            'employmentStatus' => $this->employmentStatus,
            'employmentType' => $this->employmentType,
            'staffType' => $this->staffType,
            'employeeCategoryId' => $this->employeeCategoryId,
            'primaryAssignmentId' => $this->primaryAssignmentId,
            'campusId' => $this->campusId,
            'departmentId' => $this->departmentId,
            'positionId' => $this->positionId,
        ];
    }
}
