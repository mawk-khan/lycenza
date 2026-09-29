<?php

namespace App\Domain\Fees\Http;

use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;

/**
 * FEE.1: the one JSON shape of fee setup records, shared by the /api/v1
 * controllers and the Inertia Finance pages. Money is an exact decimal
 * string plus its currency (the 0G.6 wire format), never a number. No
 * schoolId is ever exposed.
 */
final class FeeSetupPresenter
{
    /** @return array<string, mixed> */
    public static function feeHead(FeeHead $head): array
    {
        return [
            'id' => $head->id,
            'code' => $head->code,
            'name' => $head->name,
            'description' => $head->description,
            'status' => $head->status,
            'receivableLedgerAccountId' => $head->receivable_ledger_account_id,
            'revenueLedgerAccountId' => $head->revenue_ledger_account_id,
            'currency' => $head->currency,
        ];
    }

    /** @return array<string, mixed> */
    public static function structure(FeeStructure $structure, bool $withLines = false): array
    {
        $data = [
            'id' => $structure->id,
            'academicYearId' => $structure->academic_year_id,
            'gradeLevelId' => $structure->grade_level_id,
            'campusId' => $structure->campus_id,
            'code' => $structure->code,
            'name' => $structure->name,
            'status' => $structure->status,
            'supersedesFeeStructureId' => $structure->supersedes_fee_structure_id,
            'activatedAt' => $structure->activated_at?->toIso8601String(),
            'retiredAt' => $structure->retired_at?->toIso8601String(),
        ];

        if ($withLines) {
            $data['lines'] = $structure->lines->map(fn (FeeStructureLine $l) => self::line($l))->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function line(FeeStructureLine $line): array
    {
        return [
            'id' => $line->id,
            'feeHeadId' => $line->fee_head_id,
            'isOptional' => $line->is_optional,
            'frequency' => $line->frequency,
            'amount' => (string) $line->amount,
            'currency' => $line->currency,
            'installments' => $line->installments->map(fn (FeeStructureInstallment $i) => self::installment($i))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function installment(FeeStructureInstallment $installment): array
    {
        return [
            'id' => $installment->id,
            'sequence' => $installment->sequence,
            'label' => $installment->label,
            'billingPeriodKey' => $installment->billing_period_key,
            'periodStartsOn' => $installment->period_starts_on->toDateString(),
            'periodEndsOn' => $installment->period_ends_on->toDateString(),
            'dueDate' => $installment->due_date->toDateString(),
            'academicTermId' => $installment->academic_term_id,
            'amount' => (string) $installment->amount,
            'currency' => $installment->currency,
        ];
    }

    /** @return array<string, mixed> */
    public static function selection(FeeOptionalSelection $selection): array
    {
        return [
            'id' => $selection->id,
            'studentId' => $selection->student_id,
            'academicYearId' => $selection->academic_year_id,
            'feeHeadId' => $selection->fee_head_id,
            'feeStructureLineId' => $selection->fee_structure_line_id,
            'status' => $selection->status,
            'selectedAt' => $selection->created_at->toIso8601String(),
            'withdrawnAt' => $selection->withdrawn_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function run(FeeAssessmentRun $run): array
    {
        return [
            'id' => $run->id,
            'feeStructureId' => $run->fee_structure_id,
            'billingPeriodKey' => $run->billing_period_key,
            'status' => $run->status,
            'configurationVersion' => $run->configuration_version,
            'previewIsCurrent' => $run->status === FeeAssessmentRun::STATUS_PREVIEWED
                && $run->previewed_configuration_version === $run->configuration_version,
            'currency' => $run->currency,
            'preview' => [
                'readyCount' => $run->ready_count,
                'readyAmount' => (string) $run->ready_amount,
                'excludedCount' => $run->excluded_count,
                'blockedCount' => $run->blocked_count,
                'alreadyAssessedCount' => $run->already_assessed_count,
            ],
            'execution' => [
                'succeededCount' => $run->succeeded_count,
                'skippedCount' => $run->skipped_count,
                'failedCount' => $run->failed_count,
                'assessedAmount' => (string) $run->assessed_amount,
            ],
            'createdAt' => $run->created_at->toIso8601String(),
            'previewedAt' => $run->previewed_at?->toIso8601String(),
            'executionStartedAt' => $run->execution_started_at?->toIso8601String(),
            'completedAt' => $run->completed_at?->toIso8601String(),
            'cancelledAt' => $run->cancelled_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function runItem(FeeAssessmentRunItem $item): array
    {
        return [
            'id' => $item->id,
            'studentId' => $item->student_id,
            'studentEnrollmentId' => $item->student_enrollment_id,
            'campusId' => $item->campus_id,
            'enrollmentStartsOn' => $item->enrollment_starts_on?->toDateString(),
            'feeStructureLineId' => $item->fee_structure_line_id,
            'feeHeadId' => $item->fee_head_id,
            'billingPeriodKey' => $item->billing_period_key,
            'amount' => (string) $item->amount,
            'currency' => $item->currency,
            'previewResult' => $item->preview_result,
            'reason' => $item->reason,
            'excludedAt' => $item->excluded_at?->toIso8601String(),
            'executionStatus' => $item->execution_status,
            'failureReason' => $item->failure_reason,
            'feeAssessmentId' => $item->fee_assessment_id,
            'executedAt' => $item->executed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function assessment(FeeAssessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'studentId' => $assessment->student_id,
            'academicYearId' => $assessment->academic_year_id,
            'feeHeadId' => $assessment->fee_head_id,
            'billingPeriodKey' => $assessment->billing_period_key,
            'chargeId' => $assessment->charge_id,
            'feeAssessmentRunId' => $assessment->fee_assessment_run_id,
            'voidedAt' => $assessment->voided_at?->toIso8601String(),
        ];
    }
}
