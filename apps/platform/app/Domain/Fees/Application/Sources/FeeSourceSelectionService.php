<?php

namespace App\Domain\Fees\Application\Sources;

use App\Domain\Fees\Application\FeeStructureResolver;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Domain\Students\Application\StudentEnrollmentFeeTargetReadService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * OPF (ADR 0067 §8, D9): the trusted source-selection seam. An operational
 * module's application service -- after checking its OWN capability (for
 * Transport, `transport.assignments.manage`; for Hostel,
 * `hostel.residency.manage`; for Admissions, `admissions.manage`) --
 * records or withdraws optional
 * fee selection INTENT for one Student, academic year and fee head.
 *
 * - **Trusted, authorization-neutral** like ChargeService: it performs no
 *   `finance.*` check and grants none. Only an explicit allow-list of
 *   source-module application services calls it
 *   (OperationalFeeSourceArchitectureGuardTest); it has no route.
 * - **Intent, never money.** It never assesses, cancels, voids or adjusts
 *   anything. Only FEE assessment runs (`finance.fee_assessments.run`) turn
 *   an active selection into a charge.
 * - **FEE resolves the line itself**, exactly as an assessment run would:
 *   the Student's current enrollment in the year (Students' read boundary),
 *   then the active structure for its grade and campus
 *   (FeeStructureResolver), then that structure's optional line of the fee
 *   head. It never reads the source module.
 * - **Idempotent and race-safe.** The one-active-selection key
 *   (`fee_optional_selections_one_active`) is the authority: an existing
 *   active selection is reused; a concurrent insert that loses the race
 *   resolves to the winner. Withdrawal of a withdrawn selection is a no-op.
 * - **Audited by FEE**, as the human path is, with the source and actor.
 *
 * The human path (FeeOptionalSelectionService, `finance.fee_structures.manage`)
 * is unchanged.
 */
class FeeSourceSelectionService
{
    public function __construct(
        private readonly StudentEnrollmentFeeTargetReadService $enrollments,
        private readonly FeeStructureResolver $resolver,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function selectForSource(
        School $school,
        string $studentId,
        string $academicYearId,
        string $feeHeadId,
        FeeSelectionSource $source,
        ?User $actor,
    ): FeeSourceSelectionResult {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $studentId, $academicYearId, $feeHeadId, $source, $actor) {
            $head = FeeHead::query()->where('school_id', $school->id)->sharedLock()->find($feeHeadId);
            if ($head === null || ! $head->isActive()) {
                return FeeSourceSelectionResult::notApplicable(FeeSourceSelectionResult::FEE_HEAD_UNAVAILABLE);
            }

            $enrollment = $this->enrollments->currentForYear($school, $studentId, $academicYearId);
            if ($enrollment === null) {
                return FeeSourceSelectionResult::notApplicable(FeeSourceSelectionResult::NO_ENROLLMENT);
            }

            $structure = $this->resolver->activeStructureFor($school, $academicYearId, $enrollment->gradeLevelId, $enrollment->campusId);
            if ($structure === null) {
                return FeeSourceSelectionResult::notApplicable(FeeSourceSelectionResult::NO_STRUCTURE);
            }

            // FOR SHARE, as the human path: a concurrent draft-line removal waits or wins first.
            $line = FeeStructureLine::query()->where('school_id', $school->id)->where('fee_structure_id', $structure->id)
                ->where('fee_head_id', $head->id)->where('is_optional', true)->sharedLock()->first();
            if ($line === null) {
                return FeeSourceSelectionResult::notApplicable(FeeSourceSelectionResult::NO_OPTIONAL_LINE);
            }

            $existing = $this->activeSelectionId($school, $studentId, $academicYearId, $head->id);
            if ($existing !== null) {
                return FeeSourceSelectionResult::reused($existing);
            }

            try {
                // A savepoint: losing the unique-index race must not abort the caller's transaction.
                $selection = DB::transaction(function () use ($school, $studentId, $academicYearId, $head, $line, $actor) {
                    $selection = new FeeOptionalSelection;
                    $selection->forceFill([
                        'school_id' => $school->id,
                        'student_id' => $studentId,
                        'academic_year_id' => $academicYearId,
                        'fee_head_id' => $head->id,
                        'fee_structure_line_id' => $line->id,
                        'status' => FeeOptionalSelection::STATUS_ACTIVE,
                        'selected_by_user_id' => $actor?->id,
                    ])->save();

                    return $selection;
                });
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'fee_optional_selections_one_active')) {
                    throw $e;
                }
                // The concurrent winner committed (the unique index waited for it): reuse it.
                $winner = $this->activeSelectionId($school, $studentId, $academicYearId, $head->id);

                return $winner === null ? throw $e : FeeSourceSelectionResult::reused($winner);
            }

            $this->audit->school($school, 'fee_optional_selection.created', actor: $actor, subject: $selection, metadata: [
                'selectionId' => $selection->id,
                'studentId' => $studentId,
                'feeHeadId' => $head->id,
                'academicYearId' => $academicYearId,
                ...$source->toAudit(),
            ]);

            return FeeSourceSelectionResult::created($selection->id);
        }));
    }

    /**
     * Withdraws one selection a source recorded intent for. Final, future
     * assessment only (an existing charge is never touched). Returns false
     * when it was already withdrawn (idempotent).
     */
    public function withdrawForSource(School $school, string $selectionId, FeeSelectionSource $source, ?User $actor): bool
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $selectionId, $source, $actor) {
            $selection = FeeOptionalSelection::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($selectionId);
            if (! $selection->isActive()) {
                return false;
            }

            $selection->forceFill([
                'status' => FeeOptionalSelection::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
                'withdrawn_by_user_id' => $actor?->id,
            ])->save();

            $this->audit->school($school, 'fee_optional_selection.withdrawn', actor: $actor, subject: $selection, metadata: [
                'selectionId' => $selection->id,
                'studentId' => $selection->student_id,
                'feeHeadId' => $selection->fee_head_id,
                'academicYearId' => $selection->academic_year_id,
                ...$source->toAudit(),
            ]);

            return true;
        }));
    }

    /**
     * Whether a fee head can be named by a source module's tier mapping: an
     * active head of this School. A summary only (no accounts, no amounts).
     *
     * @return array{id: string, code: string, name: string}|null
     */
    public function selectableFeeHead(School $school, string $feeHeadId): ?array
    {
        $head = $this->context->withSchool($school, fn () => FeeHead::query()->where('school_id', $school->id)->find($feeHeadId));

        return $head === null || ! $head->isActive() ? null : ['id' => $head->id, 'code' => $head->code, 'name' => $head->name];
    }

    private function activeSelectionId(School $school, string $studentId, string $academicYearId, string $feeHeadId): ?string
    {
        $id = FeeOptionalSelection::query()->where('school_id', $school->id)->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)->where('fee_head_id', $feeHeadId)
            ->where('status', FeeOptionalSelection::STATUS_ACTIVE)->value('id');

        return $id === null ? null : (string) $id;
    }
}
