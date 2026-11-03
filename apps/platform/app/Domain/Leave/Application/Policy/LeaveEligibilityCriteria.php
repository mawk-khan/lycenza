<?php

namespace App\Domain\Leave\Application\Policy;

use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\Leave\Infrastructure\LeaveEligibilityRule;

/**
 * HR.9 (ADR 0065 §28.3): one eligibility rule's criteria, matched against an
 * employment's HR.1A workforce facts on a date. Every stated dimension must
 * match exactly; NULL = any value, including none (an unclassified staff
 * type or a missing category or Department matches only a NULL criterion).
 * A Department matches exactly -- never its parent or children.
 *
 * Never gender, marital status, family, health or any other HR-L8 / HRX-L1
 * attribute: those dimensions do not exist.
 */
final readonly class LeaveEligibilityCriteria
{
    public ?string $employeeCategoryId;

    public ?string $departmentId;

    /** Ids are normalized to lower case: one UUID has one spelling (comparison, uniqueness, preview). */
    public function __construct(
        public ?StaffType $staffType,
        ?string $employeeCategoryId,
        ?string $departmentId,
    ) {
        $this->employeeCategoryId = $employeeCategoryId === null ? null : strtolower($employeeCategoryId);
        $this->departmentId = $departmentId === null ? null : strtolower($departmentId);
    }

    public static function ofRule(LeaveEligibilityRule $rule): self
    {
        return new self($rule->staff_type === null ? null : StaffType::from($rule->staff_type), $rule->employee_category_id, $rule->department_id);
    }

    public function matches(?string $staffType, ?string $employeeCategoryId, ?string $departmentId): bool
    {
        return ($this->staffType === null || $this->staffType->value === $staffType)
            && ($this->employeeCategoryId === null || $this->employeeCategoryId === $employeeCategoryId)
            && ($this->departmentId === null || $this->departmentId === $departmentId);
    }

    /** Whether one employment could match both (the database's `leave_eligibility_overlap` predicate). */
    public function overlaps(self $other): bool
    {
        return ($this->staffType === null || $other->staffType === null || $this->staffType === $other->staffType)
            && ($this->employeeCategoryId === null || $other->employeeCategoryId === null || $this->employeeCategoryId === $other->employeeCategoryId)
            && ($this->departmentId === null || $other->departmentId === null || $this->departmentId === $other->departmentId);
    }

    public function key(): string
    {
        return ($this->staffType->value ?? '*').'|'.($this->employeeCategoryId ?? '*').'|'.($this->departmentId ?? '*');
    }

    /** @return array{staffType: ?string, employeeCategoryId: ?string, departmentId: ?string} */
    public function toArray(): array
    {
        return ['staffType' => $this->staffType?->value, 'employeeCategoryId' => $this->employeeCategoryId, 'departmentId' => $this->departmentId];
    }
}
