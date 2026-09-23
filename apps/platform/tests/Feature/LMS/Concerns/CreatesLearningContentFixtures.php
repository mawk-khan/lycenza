<?php

namespace Tests\Feature\LMS\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * Phase 0I.2 fixture helpers. `Tests\Concerns\CreatesTenancyFixtures` is
 * used read-only here, never modified in this trait -- the same
 * discipline `CreatesSyllabusFixtures`/`CreatesCurriculumDeliveryFixtures`
 * already follow.
 */
trait CreatesLearningContentFixtures
{
    use CreatesTenancyFixtures;

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear,
     *               grade: GradeLevel, offering: SubjectOffering, actor: User}
     */
    protected function learningContentWorld(array $offeringAttributes = []): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, [
            'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31',
        ]);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, array_merge([
            'is_required' => true, 'status' => 'active',
        ], $offeringAttributes));

        return [
            'school' => $school, 'campus' => $campus, 'year' => $year,
            'grade' => $grade, 'subject' => $subject, 'offering' => $offering,
            'actor' => $this->fullLearningContentActor($school),
        ];
    }

    protected function createLearningContent(SubjectOffering $offering, array $attributes = []): LearningContent
    {
        return app(TenantContext::class)->withSchool(
            $offering->school,
            fn () => LearningContent::factory()->create(array_merge([
                'school_id' => $offering->school_id,
                'subject_offering_id' => $offering->id,
            ], $attributes)),
        );
    }

    protected function fullLearningContentActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, ['lms.content.view', 'lms.content.manage']);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
