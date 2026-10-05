<?php

namespace App\Domain\Transport\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Fees\Application\Sources\FeeSelectionSource;
use App\Domain\Fees\Application\Sources\FeeSourceSelectionService;
use App\Domain\Transport\Application\Exceptions\TransportCarryForwardYearInvalidException;
use App\Domain\Transport\Application\Exceptions\TransportFeeHeadNotSelectableException;
use App\Domain\Transport\Infrastructure\TransportFeeSelection;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportRouteFeeHead;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * OPF.1 (ADR 0067 §14): Transport's side of the Operational Fee Integration
 * -- the ONLY writer of `transport_route_fee_heads` and
 * `transport_fee_selections`.
 *
 * - **Intent, never money.** Starting a Student assignment records Transport
 *   fee selection intent for the year; ending it withdraws every selection
 *   it recorded. Both go through FEE's trusted source seam
 *   (FeeSourceSelectionService). Nothing here assesses, cancels or alters a
 *   charge; only FEE assessment runs (`finance.fee_assessments.run`) make
 *   money, a full period at a time (no proration, D5).
 * - **Amounts stay in FEE (D7).** A route maps to a fee head; FEE's
 *   instalments own the amount. An unmapped route records nothing and never
 *   fails the operational assignment.
 * - **Academic year.** A new assignment records intent for the School's
 *   active year (CurrentAcademicYearResolver). Later years only through the
 *   explicit, audited carry-forward (D8); nothing carries itself.
 * - **Exactly once.** One provenance row per assignment x year
 *   (`transport_fee_selections_one_per_year`) plus FEE's one-active-selection
 *   key. The assignment row is locked first, so a concurrent end() or
 *   carry-forward of the same assignment serializes behind it.
 * - **Authorization** is the caller's Transport capability
 *   (`transport.assignments.manage` / `transport.routes.manage`, checked by
 *   the controllers); this grants no Finance capability (D9).
 */
class TransportFeeSelectionService
{
    public function __construct(
        private readonly FeeSourceSelectionService $fees,
        private readonly CurrentAcademicYearResolver $years,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Called by TransportStudentAssignmentService::assign() inside its
     * transaction, right after the assignment row is written.
     */
    public function recordForNewAssignment(TransportStudentAssignment $assignment, ?User $actor): void
    {
        $school = $assignment->school;
        $mapping = $this->mappingFor($assignment->route_id);
        if ($mapping === null) {
            return; // an unmapped route bills nothing
        }

        $year = $this->years->tryResolve($school);
        if ($year === null) {
            $this->notApplicable($school, $assignment, null, $mapping->fee_head_id, 'no_active_academic_year', TransportFeeSelection::REASON_ASSIGNMENT, $actor);

            return;
        }

        $this->link($assignment, $year->id, TransportFeeSelection::REASON_ASSIGNMENT, $actor);
    }

    /**
     * Called by TransportStudentAssignmentService::end() inside its
     * transaction: withdraws every still-active selection this assignment
     * recorded (future assessment only; no charge is touched).
     */
    public function withdrawForEndedAssignment(TransportStudentAssignment $assignment, ?User $actor): void
    {
        $school = $assignment->school;
        $links = TransportFeeSelection::query()->where('transport_student_assignment_id', $assignment->id)->orderBy('academic_year_id')->get();

        foreach ($links as $link) {
            if ($this->fees->withdrawForSource($school, $link->fee_optional_selection_id, FeeSelectionSource::transport($assignment->id), $actor)) {
                $this->audit->school($school, 'transport.fee_selection.withdrawn', actor: $actor, subject: $assignment, metadata: [
                    'transportStudentAssignmentId' => $assignment->id,
                    'studentId' => $assignment->student_id,
                    'academicYearId' => $link->academic_year_id,
                    'feeHeadId' => $link->fee_head_id,
                    'feeOptionalSelectionId' => $link->fee_optional_selection_id,
                ]);
            }
        }
    }

    /**
     * D8: records next year's Transport fee intent for every assignment that
     * is still active. Idempotent (a linked assignment-year is skipped) and
     * race-safe (each assignment is locked and re-checked in its own
     * transaction). Audited once as a summary, and per newly linked
     * assignment.
     *
     * @return array{linked: int, already_linked: int, unmapped: int, ended: int, not_applicable: int}
     */
    public function carryForward(School $school, string $academicYearId, ?User $actor): array
    {
        if ($this->years->openYear($school, $academicYearId) === null) {
            throw new TransportCarryForwardYearInvalidException;
        }

        $totals = ['linked' => 0, 'already_linked' => 0, 'unmapped' => 0, 'ended' => 0, 'not_applicable' => 0];

        $this->context->withSchool($school, function () use ($school, $academicYearId, $actor, &$totals): void {
            TransportStudentAssignment::query()->where('school_id', $school->id)->where('status', 'active')->orderBy('id')
                ->select('id')->chunkById(200, function ($assignments) use ($academicYearId, $actor, &$totals): void {
                    foreach ($assignments as $row) {
                        $outcome = DB::transaction(function () use ($row, $academicYearId, $actor): string {
                            $assignment = TransportStudentAssignment::query()->lockForUpdate()->find($row->id);
                            if ($assignment === null || ! $assignment->isActive()) {
                                return 'ended';
                            }

                            return $this->link($assignment, $academicYearId, TransportFeeSelection::REASON_CARRY_FORWARD, $actor);
                        });
                        $totals[$outcome]++;
                    }
                });

            $this->audit->school($school, 'transport.fee_selection.carried_forward', actor: $actor, metadata: [
                'academicYearId' => $academicYearId,
                ...$totals,
            ]);
        });

        return $totals;
    }

    /**
     * D7: maps a route (pricing tier) to an active fee head of its School, or
     * clears the mapping (null). Affects only assignments started afterwards
     * and later carry-forwards; recorded intent is never rewritten.
     */
    public function setRouteFeeHead(TransportRoute $route, ?string $feeHeadId, ?User $actor): ?TransportRouteFeeHead
    {
        $school = $route->school;
        if ($feeHeadId !== null && $this->fees->selectableFeeHead($school, $feeHeadId) === null) {
            throw new TransportFeeHeadNotSelectableException;
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $route, $feeHeadId, $actor) {
            TransportRoute::query()->whereKey($route->id)->lockForUpdate()->firstOrFail();
            $current = TransportRouteFeeHead::query()->where('route_id', $route->id)->first();
            $previous = $current?->fee_head_id;

            if ($feeHeadId === null) {
                $current?->delete();
            } elseif ($current === null) {
                $current = TransportRouteFeeHead::query()->create(['school_id' => $school->id, 'route_id' => $route->id, 'fee_head_id' => $feeHeadId]);
            } else {
                $current->update(['fee_head_id' => $feeHeadId]);
            }

            if ($previous !== $feeHeadId) {
                $this->audit->school($school, $feeHeadId === null ? 'transport.route_fee_head.cleared' : 'transport.route_fee_head.set', actor: $actor, subject: $route, metadata: [
                    'transportRouteId' => $route->id,
                    'feeHeadId' => $feeHeadId,
                    'previousFeeHeadId' => $previous,
                ]);
            }

            return $feeHeadId === null ? null : $current;
        }));
    }

    /** @return array{feeHeadId: string, code: string, name: string}|null the route's mapped fee head */
    public function routeFeeHead(TransportRoute $route): ?array
    {
        $mapping = $this->context->withSchool($route->school, fn () => $this->mappingFor($route->id));
        $head = $mapping === null ? null : $this->fees->selectableFeeHead($route->school, $mapping->fee_head_id);

        return $mapping === null ? null : [
            'feeHeadId' => $mapping->fee_head_id,
            'code' => $head['code'] ?? '',
            'name' => $head['name'] ?? '',
        ];
    }

    /**
     * Records intent for one locked, active assignment and one year.
     *
     * @return 'linked'|'already_linked'|'unmapped'|'not_applicable'
     */
    private function link(TransportStudentAssignment $assignment, string $academicYearId, string $reason, ?User $actor): string
    {
        $school = $assignment->school;
        if (TransportFeeSelection::query()->where('transport_student_assignment_id', $assignment->id)->where('academic_year_id', $academicYearId)->exists()) {
            return 'already_linked';
        }

        $mapping = $this->mappingFor($assignment->route_id);
        if ($mapping === null) {
            return 'unmapped';
        }

        $result = $this->fees->selectForSource($school, $assignment->student_id, $academicYearId, $mapping->fee_head_id, FeeSelectionSource::transport($assignment->id), $actor);
        if (! $result->hasSelection()) {
            $this->notApplicable($school, $assignment, $academicYearId, $mapping->fee_head_id, (string) $result->reason, $reason, $actor);

            return 'not_applicable';
        }

        try {
            // A savepoint: a concurrent identical link must not abort the caller's transaction.
            $link = DB::transaction(fn () => TransportFeeSelection::query()->create([
                'school_id' => $school->id,
                'transport_student_assignment_id' => $assignment->id,
                'academic_year_id' => $academicYearId,
                'fee_head_id' => $mapping->fee_head_id,
                'fee_optional_selection_id' => $result->selectionId,
                'link_reason' => $reason,
                'selection_outcome' => $result->outcome,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'transport_fee_selections_one_per_year')) {
                throw $e;
            }

            return 'already_linked';
        }

        $this->audit->school($school, 'transport.fee_selection.linked', actor: $actor, subject: $assignment, metadata: [
            'transportStudentAssignmentId' => $assignment->id,
            'studentId' => $assignment->student_id,
            'academicYearId' => $academicYearId,
            'feeHeadId' => $mapping->fee_head_id,
            'feeOptionalSelectionId' => $link->fee_optional_selection_id,
            'selectionOutcome' => $result->outcome,
            'linkReason' => $reason,
        ]);

        return 'linked';
    }

    private function mappingFor(string $routeId): ?TransportRouteFeeHead
    {
        return TransportRouteFeeHead::query()->where('route_id', $routeId)->first();
    }

    /** A mapped route whose fee intent could not be recorded: actionable, so audited (never fails the assignment). */
    private function notApplicable(School $school, TransportStudentAssignment $assignment, ?string $academicYearId, string $feeHeadId, string $why, string $reason, ?User $actor): void
    {
        $this->audit->school($school, 'transport.fee_selection.not_applicable', actor: $actor, subject: $assignment, metadata: [
            'transportStudentAssignmentId' => $assignment->id,
            'studentId' => $assignment->student_id,
            'academicYearId' => $academicYearId,
            'feeHeadId' => $feeHeadId,
            'reason' => $why,
            'linkReason' => $reason,
        ]);
    }
}
