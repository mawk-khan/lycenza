<?php

namespace Tests\Feature\Examinations\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 0H.4B fixture helpers, built ON TOP of
 * Tests\Feature\Examinations\Concerns\CreatesExaminationFixtures (used
 * read-only, never modified -- the same discipline that trait itself
 * follows for CreatesTenancyFixtures).
 *
 * An ExaminationPaper's `examination_id` and `subject_offering_id` must
 * both belong to the SAME School AND the SAME AcademicYear
 * (structurally enforced by the composite FKs), so
 * `examinationPaperWorld()` builds one Examination and one active
 * SubjectOffering sharing the same AcademicYear explicitly -- never
 * defaulted -- exactly like `createSubjectOffering()`'s own precedent.
 */
trait CreatesExaminationPaperFixtures
{
    use CreatesExaminationFixtures;

    /**
     * @return array{school: School, year: AcademicYear, examination: Examination, campus: Campus, gradeLevel: GradeLevel, subject: Subject, subjectOffering: SubjectOffering, actor: User}
     */
    protected function examinationPaperWorld(array $yearAttributes = [], array $examinationAttributes = []): array
    {
        $w = $this->examinationWorld($yearAttributes);

        $examination = $this->createExamination($w['year'], array_merge([
            'starts_on' => $this->today()->addDays(5)->toDateString(),
            'ends_on' => $this->today()->addDays(35)->toDateString(),
        ], $examinationAttributes));

        $campus = $this->createCampus($w['school']);
        $gradeLevel = $this->createGradeLevel($w['school']);
        $subject = $this->createSubject($w['school']);
        $subjectOffering = $this->createSubjectOffering($w['year'], $campus, $gradeLevel, $subject);

        return [
            'school' => $w['school'],
            'year' => $w['year'],
            'examination' => $examination,
            'campus' => $campus,
            'gradeLevel' => $gradeLevel,
            'subject' => $subject,
            'subjectOffering' => $subjectOffering,
            'actor' => $this->fullExaminationPaperActor($w['school']),
        ];
    }

    /**
     * Builds an ExaminationPaper directly, bypassing the service, so a
     * test can arrange an EXISTING row without depending on the very
     * write path it is about to exercise.
     */
    protected function createExaminationPaper(Examination $examination, SubjectOffering $subjectOffering, array $attributes = []): ExaminationPaper
    {
        return app(TenantContext::class)->withSchool(
            $examination->school,
            fn () => ExaminationPaper::factory()->create(array_merge([
                'school_id' => $examination->school_id,
                'examination_id' => $examination->id,
                'subject_offering_id' => $subjectOffering->id,
                'academic_year_id' => $examination->academic_year_id,
                'campus_id' => $subjectOffering->campus_id,
                'grade_level_id' => $subjectOffering->grade_level_id,
            ], $attributes)),
        );
    }

    protected function fullExaminationPaperActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, [
            'examinations.definitions.view', 'examinations.definitions.manage',
            'examinations.papers.view', 'examinations.papers.manage',
        ]);
    }
}
