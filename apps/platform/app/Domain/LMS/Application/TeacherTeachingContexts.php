<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * TCH.5C/TCH.5D -- the Subject Offerings a teacher teaches today, each with
 * ONLY the Sections they teach: the audience picker of both owned LMS
 * surfaces. A self projection -- never `teaching.assignments.view` or a
 * School Section list.
 */
class TeacherTeachingContexts
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array<string, mixed>> */
    public function forScope(School $school, TeacherLmsScope $scope): array
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
}
