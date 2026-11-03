<?php

namespace App\Domain\Leave\Application\Accrual;

/**
 * HR.10: one employment's accrual answer for one month, computed by the
 * server without mutating anything (preview) or under the batch locks
 * (execution). `eligibilityDrift`: the HR.9 rules on the month's first day
 * no longer resolve to exactly the assigned version (shown, never acted on).
 */
final readonly class LeaveAccrualEvaluation
{
    public function __construct(
        public string $employmentRecordId,
        public ?string $employeeNumber,
        public ?string $fullName,
        public LeaveAccrualOutcome $outcome,
        public ?int $units,
        public ?string $assignmentId,
        public ?string $policyId,
        public ?string $postableOn,
        public bool $eligibilityDrift,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employmentRecordId' => $this->employmentRecordId, 'employeeNumber' => $this->employeeNumber, 'fullName' => $this->fullName,
            'outcome' => $this->outcome->value, 'units' => $this->units, 'assignmentId' => $this->assignmentId, 'policyId' => $this->policyId,
            'postableOn' => $this->postableOn, 'eligibilityDrift' => $this->eligibilityDrift,
        ];
    }
}
