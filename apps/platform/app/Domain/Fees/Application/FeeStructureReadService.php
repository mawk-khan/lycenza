<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\FeeHeadNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeStructureNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeOptionalSelectionException;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * FEE.1 (ADR 0062 §7, §8): the authorized read path for fee heads, fee
 * structures and optional selections. `finance.fee_structures.view` gates
 * every method; a School the actor cannot view learns nothing.
 *
 * Fee heads and structures are Confidential School pricing (no personal
 * data). Optional selections link a Student to a fee and are Highly
 * Sensitive (ADR 0062 §21), so listing them writes a read audit
 * (`fee_optional_selection.list_viewed`, the `charge.list_viewed`
 * precedent).
 */
class FeeStructureReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly FeeStructureResolver $resolver,
    ) {}

    /** @return Collection<int, FeeHead> */
    public function listFeeHeads(School $school, User $actor, bool $includeInactive = true): Collection
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, fn () => FeeHead::query()
            ->where('school_id', $school->id)
            ->when(! $includeInactive, fn ($q) => $q->where('status', FeeHead::STATUS_ACTIVE))
            ->orderBy('code')
            ->get());
    }

    public function getFeeHead(School $school, string $feeHeadId, User $actor): FeeHead
    {
        $this->authorize($school, $actor);

        $head = $this->context->withSchool($school, fn () => FeeHead::query()->where('school_id', $school->id)->find($feeHeadId));

        if ($head === null) {
            throw new FeeHeadNotFoundException($feeHeadId);
        }

        return $head;
    }

    /**
     * @param  array{academic_year_id?: string, grade_level_id?: string, campus_id?: string, status?: string}  $filters
     * @return Collection<int, FeeStructure>
     */
    public function listStructures(School $school, array $filters, User $actor): Collection
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, fn () => FeeStructure::query()
            ->where('school_id', $school->id)
            ->when(isset($filters['academic_year_id']), fn ($q) => $q->where('academic_year_id', $filters['academic_year_id']))
            ->when(isset($filters['grade_level_id']), fn ($q) => $q->where('grade_level_id', $filters['grade_level_id']))
            ->when(isset($filters['campus_id']), fn ($q) => $q->where('campus_id', $filters['campus_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('academic_year_id')
            ->orderBy('code')
            ->get());
    }

    /** The structure with its lines (fee head + instalments). */
    public function getStructure(School $school, string $feeStructureId, User $actor): FeeStructure
    {
        $this->authorize($school, $actor);

        $structure = $this->context->withSchool($school, fn () => FeeStructure::query()
            ->where('school_id', $school->id)
            ->with(['lines' => fn ($q) => $q->orderBy('id'), 'lines.feeHead', 'lines.installments'])
            ->find($feeStructureId));

        if ($structure === null) {
            throw new FeeStructureNotFoundException($feeStructureId);
        }

        return $structure;
    }

    /**
     * The ACTIVE structure that applies to an AcademicYear x GradeLevel x
     * Campus: the campus-specific override when one is active, otherwise the
     * School-wide default (campus NULL), otherwise none (ADR 0062 §7.1).
     */
    public function resolveActiveStructure(School $school, string $academicYearId, string $gradeLevelId, ?string $campusId, User $actor): ?FeeStructure
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, fn () => $this->resolver->activeStructureFor($school, $academicYearId, $gradeLevelId, $campusId));
    }

    /**
     * Callers must narrow by Student or by line (the HTTP layer requires
     * one), so the result is bounded by one Student's fees or one grade's
     * Students -- never the whole School.
     *
     * @param  array{student_id?: string, fee_structure_line_id?: string, fee_structure_line_ids?: list<string>, fee_head_id?: string, academic_year_id?: string, status?: string}  $filters
     * @return Collection<int, FeeOptionalSelection>
     */
    public function listSelections(School $school, array $filters, User $actor): Collection
    {
        $this->authorize($school, $actor);

        if (! isset($filters['student_id']) && ! isset($filters['fee_structure_line_id']) && ! isset($filters['fee_structure_line_ids'])) {
            throw new InvalidFeeOptionalSelectionException('student_id', 'Filter optional fee selections by student_id or fee_structure_line_id.');
        }

        return $this->context->withSchool($school, function () use ($school, $filters, $actor) {
            $selections = FeeOptionalSelection::query()
                ->where('school_id', $school->id)
                ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
                ->when(isset($filters['fee_structure_line_id']), fn ($q) => $q->where('fee_structure_line_id', $filters['fee_structure_line_id']))
                ->when(isset($filters['fee_structure_line_ids']), fn ($q) => $q->whereIn('fee_structure_line_id', $filters['fee_structure_line_ids']))
                ->when(isset($filters['fee_head_id']), fn ($q) => $q->where('fee_head_id', $filters['fee_head_id']))
                ->when(isset($filters['academic_year_id']), fn ($q) => $q->where('academic_year_id', $filters['academic_year_id']))
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $this->audit->school($school, 'fee_optional_selection.list_viewed', actor: $actor, metadata: [
                'resultCount' => $selections->count(),
            ]);

            return $selections;
        });
    }

    private function authorize(School $school, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.view', $school);
    }
}
