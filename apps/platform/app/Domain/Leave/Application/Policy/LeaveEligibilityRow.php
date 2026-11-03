<?php

namespace App\Domain\Leave\Application\Policy;

/**
 * HR.9: one employment's eligibility answer -- its identity labels, the
 * workforce facts it was judged on, the closed outcome and the version and
 * rule it resolved to. Never a protected attribute, contact, pay or HR
 * profile field.
 */
final readonly class LeaveEligibilityRow
{
    /** @param  list<string>  $matchingPolicyIds */
    public function __construct(
        public string $employmentRecordId,
        public string $employeeId,
        public ?string $employeeNumber,
        public ?string $fullName,
        public string $employmentStatus,
        public ?string $staffType,
        public ?string $employeeCategoryId,
        public ?string $departmentId,
        public LeaveEligibilityOutcome $outcome,
        public ?string $policyId,
        public ?string $ruleId,
        public array $matchingPolicyIds,
        public ?string $assignmentId,
        public ?string $assignedPolicyId,
        public ?string $assignmentEndsOn,
    ) {}

    /** Whether the rules today resolve to the version already assigned (null: not assigned, or no single match). */
    public function assignedMatchesRules(): ?bool
    {
        if ($this->outcome !== LeaveEligibilityOutcome::ASSIGNED) {
            return null;
        }

        return count($this->matchingPolicyIds) === 1 ? $this->matchingPolicyIds[0] === $this->assignedPolicyId : false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employmentRecordId' => $this->employmentRecordId,
            'employeeId' => $this->employeeId,
            'employeeNumber' => $this->employeeNumber,
            'fullName' => $this->fullName,
            'employmentStatus' => $this->employmentStatus,
            'facts' => ['staffType' => $this->staffType, 'employeeCategoryId' => $this->employeeCategoryId, 'departmentId' => $this->departmentId],
            'outcome' => $this->outcome->value,
            'policyId' => $this->policyId,
            'ruleId' => $this->ruleId,
            'matchingPolicyIds' => $this->matchingPolicyIds,
            'assignmentId' => $this->assignmentId,
            'assignedPolicyId' => $this->assignedPolicyId,
            'assignedMatchesRules' => $this->assignedMatchesRules(),
            'assignmentEndsOn' => $this->assignmentEndsOn,
        ];
    }
}
