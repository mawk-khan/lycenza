<?php

namespace Tests\Feature\Examinations\Concerns;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Application\Marks\StudentMarkEntry;
use App\Domain\Examinations\Application\Marks\StudentMarkReadService;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkRevision;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;

/**
 * RES.2: a School in 2026-27 (active) with Grade 5 Sections A1 / A2 on one
 * campus, a required and an elective SubjectOffering, an Examination window
 * (2026-09-01..30) and one paper per Offering (required: 2026-09-15, max 80;
 * elective: 2026-09-16, max 50). Students are placed, elected and authorised
 * through the real Students services. SYNTHETIC data only (RES-L0 condition 10).
 */
trait CreatesStudentMarkFixtures
{
    use CreatesExaminationPaperFixtures, CreatesMfaFixtures;

    /** @return array<string, mixed> */
    protected function marksWorld(): array
    {
        $school = $this->createSchool();
        $w = ['school' => $school, 'campus' => $this->createCampus($school)];
        $w['year'] = $this->createAcademicYear($school, ['code' => 'Y26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $w['grade'] = $this->createGradeLevel($school, ['code' => 'G5', 'name' => 'Grade 5', 'sequence' => 5]);
        $w['a1'] = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'A1', 'name' => '5 A1']);
        $w['a2'] = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'A2', 'name' => '5 A2']);
        $w['required'] = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($school), ['is_required' => true, 'status' => 'active']);
        $w['elective'] = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($school), ['is_required' => false, 'status' => 'active']);
        $w['examination'] = $this->createExamination($w['year'], ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'active']);
        $w['paper'] = $this->createExaminationPaper($w['examination'], $w['required'], ['scheduled_on' => '2026-09-15', 'max_marks' => '80.00']);
        $w['electivePaper'] = $this->createExaminationPaper($w['examination'], $w['elective'], ['scheduled_on' => '2026-09-16', 'max_marks' => '50.00']);
        $w['admin'] = $this->createUserWithCapabilities($school, ['examinations.marks.view', 'examinations.marks.manage']);
        $this->enrollActiveMfaFactor($w['admin']);

        return $w;
    }

    /** A Student placed in $section from 2026-06-01 and authorised (statutory School purpose) unless told otherwise. @param  array<string, mixed>  $w */
    protected function markStudent(array $w, string $section = 'a1', bool $authorised = true): Student
    {
        static $roll = 0;
        $student = $this->createStudent($w['school'], ['date_of_birth' => '2016-01-01']);
        app(StudentEnrollmentService::class)->enroll($student, $w[$section], (string) ++$roll, '2026-06-01');
        if ($authorised) {
            $this->authorise($w, $student);
        }

        return $student;
    }

    /** @param  array<string, mixed>  $w */
    protected function authorise(array $w, Student $student): StudentProcessingAuthorization
    {
        return app(StudentProcessingAuthorizationService::class)->recordStatutorySchoolPurpose($w['school'], $student, ProcessingAuthorizationPurpose::AcademicRecords, $w['admin']);
    }

    /** @param  array<string, mixed>  $w */
    protected function withdrawAuthorisation(array $w, StudentProcessingAuthorization $grant): void
    {
        app(StudentProcessingAuthorizationService::class)->withdraw($w['school'], $grant, $w['admin']);
    }

    /** @param  array<string, mixed>  $w */
    protected function elect(array $w, Student $student, ?SubjectOffering $offering = null, string $startsOn = '2026-06-01'): string
    {
        return app(StudentSubjectEnrollmentService::class)->enroll($student, $offering ?? $w['elective'], $startsOn)->id;
    }

    protected function entry(Student|string $student, string $status, ?string $value, ?int $expectedVersion = null): StudentMarkEntry
    {
        return new StudentMarkEntry(is_string($student) ? $student : $student->id, $status, $value, $expectedVersion);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  list<StudentMarkEntry>  $entries
     * @return list<array{studentId: string, studentMarkId: string, version: int}>
     */
    protected function recordMarks(array $w, array $entries, ?ExaminationPaper $paper = null, ?User $actor = null): array
    {
        return app(StudentMarkService::class)->record($w['school'], ($paper ?? $w['paper'])->id, $entries, $actor ?? $w['admin']);
    }

    /** @param  array<string, mixed>  $w @return array<string, mixed> */
    protected function grid(array $w, ?ExaminationPaper $paper = null): array
    {
        return app(StudentMarkReadService::class)->grid($w['school'], ($paper ?? $w['paper'])->id, $w['admin']);
    }

    /** @param  array<string, mixed>  $w */
    protected function markOf(array $w, Student $student, ?ExaminationPaper $paper = null): ?StudentMark
    {
        return $this->inMarksSchool($w['school'], fn () => StudentMark::query()->where('examination_paper_id', ($paper ?? $w['paper'])->id)->where('student_id', $student->id)->first());
    }

    /** @param  array<string, mixed>  $w @return list<StudentMarkRevision> */
    protected function revisionsOf(array $w, StudentMark $mark): array
    {
        return $this->inMarksSchool($w['school'], fn () => StudentMarkRevision::query()->where('student_mark_id', $mark->id)->orderBy('revision')->get()->all());
    }

    /** @param  array<string, mixed>  $w */
    protected function placementOf(array $w, Student $student): StudentEnrollment
    {
        return $this->inMarksSchool($w['school'], fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->firstOrFail());
    }

    protected function inMarksSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
