<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnership;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * TCH.5C (ADR 0063 section 36) -- the owned teacher READ projections for
 * Learning Content, filtered in SQL by TeacherLearningContentScope:
 *
 * - contexts(): the Subject Offerings the teacher teaches today, each with
 *   ONLY the Sections they teach (the audience picker) -- a self
 *   projection, never `teaching.assignments.view` or a School Section list;
 * - list(): every row the teacher may read, with `mine`/`canEdit` flags.
 *
 * The owner Employee id is never serialized: a teacher sees `mine`,
 * `offeringWide` and the audience Sections only.
 */
class TeacherLearningContentReadService
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array<string, mixed>> */
    public function contexts(School $school, TeacherLearningContentScope $scope): array
    {
        return $this->context->withSchool($school, function () use ($scope) {
            $offerings = SubjectOffering::query()
                ->with(['subject', 'gradeLevel', 'campus', 'academicYear'])
                ->whereIn('id', $scope->taughtOfferings())
                ->get();
            $sections = Section::query()->whereIn('id', array_column($scope->taught, 'section'))->get()->keyBy('id');

            return $offerings
                ->sortBy(fn (SubjectOffering $o) => [$o->gradeLevel?->sequence, $o->subject?->name])
                ->map(fn (SubjectOffering $o) => [
                    'subjectOfferingId' => $o->id,
                    'subjectCode' => $o->subject?->code,
                    'subjectName' => $o->subject?->name,
                    'gradeLevelName' => $o->gradeLevel?->name,
                    'campusName' => $o->campus?->name,
                    'academicYearName' => $o->academicYear?->name,
                    'sections' => collect($scope->taughtSections($o->id))
                        ->map(fn (string $id) => ['id' => $id, 'code' => $sections->get($id)?->code, 'name' => $sections->get($id)?->name])
                        ->sortBy('code')->values()->all(),
                ])->values()->all();
        });
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function list(School $school, TeacherLearningContentScope $scope, ?string $subjectOfferingId, int $perPage = 50): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($scope, $subjectOfferingId, $perPage) {
            $paginator = $scope->constrain(LearningContent::query())
                ->with('sectionAudiences')
                ->when($subjectOfferingId !== null, fn ($q) => $q->where('subject_offering_id', $subjectOfferingId))
                ->orderBy('subject_offering_id')
                ->orderBy('sequence')
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate($perPage)
                ->withQueryString();

            $codes = $this->sectionCodes($paginator->getCollection());

            return $paginator->through(fn (LearningContent $c) => $this->present($c, $scope, $codes));
        });
    }

    /** @return array<string, mixed> */
    public function presentOne(School $school, LearningContent $content, TeacherLearningContentScope $scope): array
    {
        return $this->context->withSchool($school, function () use ($content, $scope) {
            $content->load('sectionAudiences');

            return $this->present($content, $scope, $this->sectionCodes(collect([$content])));
        });
    }

    /**
     * @param  array<string, string|null>  $codes
     * @return array<string, mixed>
     */
    private function present(LearningContent $content, TeacherLearningContentScope $scope, array $codes): array
    {
        $ownership = new LmsResourceOwnership(
            $content->id,
            $content->subject_offering_id,
            $content->getAttribute('owner_employee_id'),
            $content->sectionAudiences->pluck('section_id')->sort()->values()->all(),
        );

        return [
            'id' => $content->id,
            'subjectOfferingId' => $content->subject_offering_id,
            'title' => $content->title,
            'description' => $content->description,
            'sequence' => $content->sequence,
            'status' => $content->status,
            'offeringWide' => $ownership->isOfferingWide(),
            'mine' => $scope->isOwner($ownership),
            'canEdit' => $scope->canWrite($ownership),
            'audience' => array_map(fn (string $id) => ['sectionId' => $id, 'sectionCode' => $codes[$id] ?? null], $ownership->audienceSectionIds),
        ];
    }

    /**
     * @param  Collection<int, LearningContent>  $rows
     * @return array<string, string|null>
     */
    private function sectionCodes(Collection $rows): array
    {
        $ids = $rows->flatMap(fn (LearningContent $c) => $c->sectionAudiences->pluck('section_id'))->unique()->values()->all();

        return $ids === [] ? [] : Section::query()->whereIn('id', $ids)->pluck('code', 'id')->all();
    }
}
