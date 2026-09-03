<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use Illuminate\Support\Carbon;

/**
 * Phase 9.2 -- deliberately narrow: identity and effective dates only,
 * never the Employee-specific monetary values (ADR 0034 "Sensitive
 * values"). `CompensationService` returns this from read paths a
 * broad/list context might reach; the full value set is only ever
 * loaded through `assign()`'s own return (the Eloquent model, for the
 * one caller that just performed the write) or a dedicated sensitive
 * read path Checkpoint 9.7 gates behind `payroll.compensation.sensitive.view`.
 * This shape is what makes that suppression boundary structurally
 * easy to enforce later -- there is no `amount` field here to
 * accidentally leak.
 */
final class CompensationAssignmentSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $employmentRecordId,
        public readonly string $salaryStructureId,
        public readonly Carbon $effectiveFrom,
        public readonly ?Carbon $effectiveTo,
    ) {}

    public static function fromModel(EmployeeCompensationAssignment $assignment): self
    {
        return new self(
            $assignment->id,
            $assignment->employment_record_id,
            $assignment->salary_structure_id,
            $assignment->effective_from,
            $assignment->effective_to,
        );
    }
}
