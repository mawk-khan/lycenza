<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Application\Exceptions\InvalidLateFeeRunException;
use App\Domain\Payments\Application\LateFeeAssessmentService;
use App\Domain\Payments\Application\LateFeeReadService;
use App\Domain\Payments\Application\LateFeeRunService;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FEE.5 (ADR 0062 §16.2-§16.3): the late-fee run API. Reads need
 * finance.charges.view; create/preview/execute/resume/cancel need
 * finance.fee_assessments.run; voiding a late fee (which cancels its
 * charge) needs finance.charges.manage -- on the route and in the
 * Application services. No DELETE and no direct "create a late fee"
 * operation: late fees come only from executed runs. Legal status:
 * DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED (ADR 0058 E31).
 */
class LateFeeRunController extends Controller
{
    /** @return array<string, mixed> */
    public static function presentRun(LateFeeRun $r): array
    {
        return [
            'id' => $r->id,
            'lateFeeRuleId' => $r->fee_late_fee_rule_id,
            'evaluationDate' => $r->evaluation_date->toDateString(),
            'status' => $r->status,
            'currency' => $r->currency,
            'preview' => ['readyCount' => $r->ready_count, 'readyAmount' => $r->ready_amount, 'notEligibleCount' => $r->not_eligible_count, 'alreadyAssessedCount' => $r->already_assessed_count, 'previewedAt' => $r->previewed_at?->toIso8601String()],
            'execution' => ['succeededCount' => $r->succeeded_count, 'skippedCount' => $r->skipped_count, 'failedCount' => $r->failed_count, 'assessedAmount' => $r->assessed_amount, 'startedAt' => $r->execution_started_at?->toIso8601String(), 'completedAt' => $r->completed_at?->toIso8601String()],
            'createdAt' => $r->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentItem(LateFeeRunItem $i): array
    {
        return [
            'id' => $i->id,
            'sourceChargeId' => $i->source_charge_id,
            'studentId' => $i->student_id,
            'academicYearId' => $i->academic_year_id,
            'feeHeadId' => $i->fee_head_id,
            'billingPeriodKey' => $i->billing_period_key,
            'dueDate' => $i->due_date->toDateString(),
            'finalGraceDate' => $i->final_grace_date->toDateString(),
            'outstandingAmount' => $i->outstanding_amount,
            'calculatedAmount' => $i->calculated_amount,
            'finalAmount' => $i->final_amount,
            'capApplied' => $i->cap_applied,
            'currency' => $i->currency,
            'previewResult' => $i->preview_result,
            'reason' => $i->reason,
            'executionStatus' => $i->execution_status,
            'failureReason' => $i->failure_reason,
            'executedOutstandingAmount' => $i->executed_outstanding_amount,
            'executedAmount' => $i->executed_amount,
            'executedCapApplied' => $i->executed_cap_applied,
            'lateFeeAssessmentId' => $i->late_fee_assessment_id,
            'executedAt' => $i->executed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentAssessment(LateFeeAssessment $a): array
    {
        return [
            'id' => $a->id,
            'sourceChargeId' => $a->source_charge_id,
            'lateFeeRuleId' => $a->fee_late_fee_rule_id,
            'chargeId' => $a->charge_id,
            'lateFeeRunId' => $a->late_fee_run_id,
            'voidedAt' => $a->voided_at?->toIso8601String(),
        ];
    }

    public function index(Request $request, School $school, LateFeeReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->listRuns($school, $request->user())->map(fn (LateFeeRun $r) => self::presentRun($r))->values()->all()]);
    }

    public function show(Request $request, School $school, string $run, LateFeeReadService $reads): JsonResponse
    {
        return response()->json(['data' => self::presentRun($reads->getRun($school, $run, $request->user()))]);
    }

    public function items(Request $request, School $school, string $run, LateFeeReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'preview_result' => ['sometimes', 'string', Rule::in(LateFeeRunItem::PREVIEW_RESULTS)],
            'execution_status' => ['sometimes', 'string', Rule::in(LateFeeRunItem::EXECUTION_STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = $reads->listItems($school, $run, array_intersect_key($validated, array_flip(['preview_result', 'execution_status'])), (int) ($validated['page'] ?? 1), $request->user());

        return response()->json([
            'data' => collect($page->items())->map(fn (LateFeeRunItem $i) => self::presentItem($i))->values()->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, School $school, LateFeeRunService $runs): JsonResponse
    {
        $validated = $request->validate([
            'late_fee_rule_id' => ['required', 'uuid'],
            'evaluation_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $run = $runs->create($school, $validated['late_fee_rule_id'], $validated['evaluation_date'] ?? null, $request->user());
        } catch (InvalidLateFeeRunException $e) {
            throw ValidationException::withMessages([$e->field() === 'fee_late_fee_rule_id' ? 'late_fee_rule_id' : $e->field() => [$e->getMessage()]]);
        }

        return response()->json(['data' => self::presentRun($run)], 201);
    }

    public function preview(Request $request, School $school, string $run, LateFeeRunService $runs): JsonResponse
    {
        try {
            return response()->json(['data' => self::presentRun($runs->preview($school, $run, $request->user()))]);
        } catch (InvalidLateFeeRunException $e) {
            throw ValidationException::withMessages(['late_fee_rule_id' => [$e->getMessage()]]);
        }
    }

    public function execute(Request $request, School $school, string $run, LateFeeRunService $runs): JsonResponse
    {
        return response()->json(['data' => self::presentRun($runs->execute($school, $run, $request->user()))], 202);
    }

    public function resume(Request $request, School $school, string $run, LateFeeRunService $runs): JsonResponse
    {
        return response()->json(['data' => self::presentRun($runs->resume($school, $run, $request->user()))], 202);
    }

    public function cancel(Request $request, School $school, string $run, LateFeeRunService $runs): JsonResponse
    {
        return response()->json(['data' => self::presentRun($runs->cancel($school, $run, $request->user()))]);
    }

    public function void(Request $request, School $school, string $assessment, LateFeeAssessmentService $assessments): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => self::presentAssessment($assessments->void($school, $assessment, $request->user(), $validated['reason'] ?? null))]);
    }
}
