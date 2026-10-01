<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnership;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * TCH.5D (ADR 0063 section 37) -- the owned teacher READ projections for
 * Assignments, filtered in SQL by TeacherAssignmentScope. The owner Employee
 * id is never serialized: a teacher sees `mine`, `offeringWide`, `canEdit`
 * and the audience Sections. Staff-authored Assignments only: no Student,
 * Submission, mark or feedback exists here.
 */
class TeacherAssignmentReadService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TeacherTeachingContexts $contexts,
    ) {}

    /** @return list<array<string, mixed>> */
    public function contexts(School $school, TeacherAssignmentScope $scope): array
    {
        return $this->contexts->forScope($school, $scope);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function list(School $school, TeacherAssignmentScope $scope, ?string $subjectOfferingId, int $perPage = 50): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($scope, $subjectOfferingId, $perPage) {
            $paginator = $scope->constrain(Assignment::query())
                ->with('sectionAudiences')
                ->when($subjectOfferingId !== null, fn ($q) => $q->where('subject_offering_id', $subjectOfferingId))
                ->orderBy('subject_offering_id')
                ->orderByRaw('due_on asc nulls last')
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate($perPage)
                ->withQueryString();

            $codes = $this->sectionCodes($paginator->getCollection());

            return $paginator->through(fn (Assignment $a) => $this->present($a, $scope, $codes));
        });
    }

    /** @return array<string, mixed> */
    public function presentOne(School $school, Assignment $assignment, TeacherAssignmentScope $scope): array
    {
        return $this->context->withSchool($school, function () use ($assignment, $scope) {
            $assignment->load('sectionAudiences');

            return $this->present($assignment, $scope, $this->sectionCodes(collect([$assignment])));
        });
    }

    /**
     * @param  array<string, string|null>  $codes
     * @return array<string, mixed>
     */
    private function present(Assignment $assignment, TeacherAssignmentScope $scope, array $codes): array
    {
        $ownership = new LmsResourceOwnership(
            $assignment->id,
            $assignment->subject_offering_id,
            $assignment->getAttribute('owner_employee_id'),
            $assignment->sectionAudiences->pluck('section_id')->sort()->values()->all(),
        );

        return [
            'id' => $assignment->id,
            'subjectOfferingId' => $assignment->subject_offering_id,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'dueOn' => $assignment->due_on?->toDateString(),
            'status' => $assignment->status,
            'offeringWide' => $ownership->isOfferingWide(),
            'mine' => $scope->isOwner($ownership),
            'canEdit' => $scope->canWrite($ownership),
            'audience' => array_map(fn (string $id) => ['sectionId' => $id, 'sectionCode' => $codes[$id] ?? null], $ownership->audienceSectionIds),
        ];
    }

    /**
     * @param  Collection<int, Assignment>  $rows
     * @return array<string, string|null>
     */
    private function sectionCodes(Collection $rows): array
    {
        $ids = $rows->flatMap(fn (Assignment $a) => $a->sectionAudiences->pluck('section_id'))->unique()->values()->all();

        return $ids === [] ? [] : Section::query()->whereIn('id', $ids)->pluck('code', 'id')->all();
    }
}
