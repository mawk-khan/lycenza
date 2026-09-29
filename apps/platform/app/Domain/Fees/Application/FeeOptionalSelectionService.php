<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\DuplicateFeeOptionalSelectionException;
use App\Domain\Fees\Application\Exceptions\FeeOptionalSelectionNotActiveException;
use App\Domain\Fees\Application\Exceptions\FeeOptionalSelectionNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeOptionalSelectionException;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * FEE.1 (ADR 0062 §8, decision E): the only write path for optional fee
 * selections. A Student is assessed an optional fee (FEE.2) only while an
 * active selection exists for that AcademicYear and fee head.
 *
 * - Selecting names an OPTIONAL line; the selection records that line's fee
 *   head and AcademicYear, so a successor structure keeps it.
 * - One active selection per Student x year x fee head
 *   (`fee_optional_selections_one_active`); a duplicate is a typed 409.
 * - Withdrawal is final and affects future assessment only; it never
 *   touches an existing charge. Re-selecting creates a new row.
 * - Nothing here reads Transport, Hostel, Library or any other module to
 *   infer a selection (that is the future OPF programme).
 *
 * Authorization boundary: `finance.fee_structures.manage` on every call.
 */
class FeeOptionalSelectionService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function select(School $school, string $studentId, string $feeStructureLineId, User $actor): FeeOptionalSelection
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $studentId, $feeStructureLineId, $actor) {
            return DB::transaction(function () use ($school, $studentId, $feeStructureLineId, $actor) {
                // FOR SHARE: a concurrent draft-line removal (FOR UPDATE)
                // waits for this selection or wins before it.
                $line = FeeStructureLine::query()->where('school_id', $school->id)->sharedLock()->find($feeStructureLineId);

                if ($line === null || ! $line->is_optional) {
                    throw new InvalidFeeOptionalSelectionException('fee_structure_line_id', 'The line must be an optional fee line of this School.');
                }

                $structure = FeeStructure::query()->where('school_id', $school->id)->findOrFail($line->fee_structure_id);

                $selection = new FeeOptionalSelection;
                $selection->forceFill([
                    'school_id' => $school->id,
                    'student_id' => $studentId,
                    'academic_year_id' => $structure->academic_year_id,
                    'fee_head_id' => $line->fee_head_id,
                    'fee_structure_line_id' => $line->id,
                    'status' => FeeOptionalSelection::STATUS_ACTIVE,
                    'selected_by_user_id' => $actor->id,
                ]);

                try {
                    $selection->save();
                } catch (UniqueConstraintViolationException $e) {
                    if (! str_contains($e->getMessage(), 'fee_optional_selections_one_active')) {
                        throw $e;
                    }

                    throw new DuplicateFeeOptionalSelectionException;
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'fee_optional_selections_student_fk')) {
                        throw new InvalidFeeOptionalSelectionException('student_id', 'The Student was not found in this School.');
                    }

                    throw $e;
                }

                $this->audit->school($school, 'fee_optional_selection.created', actor: $actor, subject: $selection, metadata: [
                    'selectionId' => $selection->id,
                    'studentId' => $selection->student_id,
                    'feeHeadId' => $selection->fee_head_id,
                    'academicYearId' => $selection->academic_year_id,
                ]);

                return $selection->refresh();
            });
        });
    }

    public function withdraw(School $school, string $selectionId, User $actor): FeeOptionalSelection
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $selectionId, $actor) {
            return DB::transaction(function () use ($school, $selectionId, $actor) {
                $selection = FeeOptionalSelection::query()->where('school_id', $school->id)->lockForUpdate()->find($selectionId);

                if ($selection === null) {
                    throw new FeeOptionalSelectionNotFoundException($selectionId);
                }

                if (! $selection->isActive()) {
                    throw new FeeOptionalSelectionNotActiveException($selection->id);
                }

                $selection->forceFill([
                    'status' => FeeOptionalSelection::STATUS_WITHDRAWN,
                    'withdrawn_at' => now(),
                    'withdrawn_by_user_id' => $actor->id,
                ])->save();

                $this->audit->school($school, 'fee_optional_selection.withdrawn', actor: $actor, subject: $selection, metadata: [
                    'selectionId' => $selection->id,
                    'studentId' => $selection->student_id,
                    'feeHeadId' => $selection->fee_head_id,
                    'academicYearId' => $selection->academic_year_id,
                ]);

                return $selection->refresh();
            });
        });
    }
}
