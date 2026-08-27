<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Application\AdmissionApplicationReadService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.4: AdmissionApplicationReadService -- the canonical read
 * layer. Distinct from AdmissionApplicationServiceTest/
 * AdmissionConversionServiceTest (write paths); these tests never
 * mutate state, and every read runs inside a real TenantContext
 * (matching how a real request's middleware would have already
 * established one before a future controller ran).
 */
class AdmissionApplicationReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function readService(): AdmissionApplicationReadService
    {
        return app(AdmissionApplicationReadService::class);
    }

    /**
     * @return array{school: School, applicant: Applicant, year: AcademicYear, campus: Campus, gradeLevel: GradeLevel}
     */
    private function buildContext(?School $school = null): array
    {
        $school = $school ?? $this->createSchool();
        $applicant = $this->createApplicant($school);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);

        return compact('school', 'applicant', 'year', 'campus', 'gradeLevel');
    }

    private function withCtx(School $school, \Closure $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    // ==================================================================
    // A. Application list
    // ==================================================================

    #[Test]
    public function directory_returns_only_the_current_schools_applications_newest_first_with_no_duplicates(): void
    {
        $school = $this->createSchool();
        ['applicant' => $applicantA, 'year' => $yearA, 'campus' => $campusA, 'gradeLevel' => $gradeA] = $this->buildContext($school);
        $appOld = $this->createAdmissionApplication($applicantA, $yearA, $campusA, $gradeA, ['status' => 'submitted']);

        ['applicant' => $applicantB, 'year' => $yearB, 'campus' => $campusB, 'gradeLevel' => $gradeB] = $this->buildContext($school);
        $appNew = $this->createAdmissionApplication($applicantB, $yearB, $campusB, $gradeB, ['status' => 'draft']);

        $otherSchool = $this->createSchool();
        ['applicant' => $applicantC, 'year' => $yearC, 'campus' => $campusC, 'gradeLevel' => $gradeC] = $this->buildContext($otherSchool);
        $this->createAdmissionApplication($applicantC, $yearC, $campusC, $gradeC);

        $page = $this->withCtx($school, fn () => $this->readService()->directory());

        $ids = collect($page->items())->pluck('id')->all();
        $this->assertCount(2, $ids);
        $this->assertSame(array_unique($ids), $ids, 'No duplicate rows.');
        $this->assertContains($appOld->id, $ids);
        $this->assertContains($appNew->id, $ids);

        // Newest first: whichever was created later appears first.
        $this->assertSame($appNew->id, $page->items()[0]->id);

        $first = $page->items()[0];
        $this->assertTrue($first->relationLoaded('applicant'));
        $this->assertTrue($first->relationLoaded('academicYear'));
        $this->assertTrue($first->relationLoaded('campus'));
        $this->assertTrue($first->relationLoaded('gradeLevel'));
    }

    #[Test]
    public function directory_excludes_decision_note_from_the_hydrated_columns(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, [
            'status' => 'rejected', 'decision_note' => 'Sensitive internal reasoning.',
        ]);

        $page = $this->withCtx($school, fn () => $this->readService()->directory());

        $this->assertNull($page->items()[0]->decision_note, 'decision_note must not be hydrated for the list view.');
    }

    // ==================================================================
    // B. Status filter
    // ==================================================================

    #[Test]
    public function each_status_filter_returns_only_matching_applications(): void
    {
        $school = $this->createSchool();
        $ids = [];
        foreach (['draft', 'submitted', 'accepted', 'rejected', 'withdrawn'] as $status) {
            ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
            $ids[$status] = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => $status])->id;
        }
        // converted needs real provenance (the CHECK constraint requires it).
        ['applicant' => $convertedApplicant, 'year' => $convertedYear, 'campus' => $convertedCampus, 'gradeLevel' => $convertedGrade] = $this->buildContext($school);
        $student = $this->createStudent($school, ['student_number' => 'S-READ-CONV']);
        $section = $this->createSection($convertedYear, $convertedCampus, $convertedGrade);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $ids['converted'] = $this->withCtx($school, fn () => AdmissionApplication::factory()->create([
            'school_id' => $convertedApplicant->school_id,
            'applicant_id' => $convertedApplicant->id,
            'academic_year_id' => $convertedYear->id,
            'campus_id' => $convertedCampus->id,
            'grade_level_id' => $convertedGrade->id,
            'status' => 'converted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => now(),
        ]))->id;

        foreach ($ids as $status => $expectedId) {
            $page = $this->withCtx($school, fn () => $this->readService()->directory(['status' => $status]));
            $resultIds = collect($page->items())->pluck('id')->all();
            $this->assertSame([$expectedId], $resultIds, "status={$status} filter must return exactly that one application.");
        }
    }

    #[Test]
    public function an_unrecognized_status_value_is_silently_ignored_rather_than_used_in_the_query(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'draft']);

        $page = $this->withCtx($school, fn () => $this->readService()->directory(['status' => 'not_a_real_status']));

        $this->assertCount(1, $page->items(), 'An unknown status value must not filter out every row -- it is ignored.');
    }

    // ==================================================================
    // C. Academic filters
    // ==================================================================

    #[Test]
    public function academic_year_campus_and_grade_level_filters_work_independently_and_combined(): void
    {
        $school = $this->createSchool();
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $target = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        ['applicant' => $applicant2] = $this->buildContext($school);
        $otherYear = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $this->createAdmissionApplication($applicant2, $otherYear, $campus, $gradeLevel);

        $byYear = $this->withCtx($school, fn () => $this->readService()->directory(['academic_year_id' => $year->id]));
        $this->assertSame([$target->id], collect($byYear->items())->pluck('id')->all());

        $byCampus = $this->withCtx($school, fn () => $this->readService()->directory(['campus_id' => $campus->id]));
        $this->assertContains($target->id, collect($byCampus->items())->pluck('id')->all());

        $byGrade = $this->withCtx($school, fn () => $this->readService()->directory(['grade_level_id' => $gradeLevel->id]));
        $this->assertContains($target->id, collect($byGrade->items())->pluck('id')->all());

        $combined = $this->withCtx($school, fn () => $this->readService()->directory([
            'academic_year_id' => $year->id, 'campus_id' => $campus->id, 'grade_level_id' => $gradeLevel->id,
        ]));
        $this->assertSame([$target->id], collect($combined->items())->pluck('id')->all());
    }

    #[Test]
    public function a_foreign_school_filter_id_matches_zero_rows_never_widens_the_query(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $otherSchool = $this->createSchool();
        $foreignYear = $this->createAcademicYear($otherSchool);
        $foreignCampus = $this->createCampus($otherSchool);
        $foreignGrade = $this->createGradeLevel($otherSchool);

        $byForeignYear = $this->withCtx($school, fn () => $this->readService()->directory(['academic_year_id' => $foreignYear->id]));
        $byForeignCampus = $this->withCtx($school, fn () => $this->readService()->directory(['campus_id' => $foreignCampus->id]));
        $byForeignGrade = $this->withCtx($school, fn () => $this->readService()->directory(['grade_level_id' => $foreignGrade->id]));

        $this->assertCount(0, $byForeignYear->items());
        $this->assertCount(0, $byForeignCampus->items());
        $this->assertCount(0, $byForeignGrade->items());
    }

    // ==================================================================
    // D. Applicant name search (on the directory)
    // ==================================================================

    #[Test]
    public function applicant_name_filter_matches_first_or_last_name_case_insensitively_and_stays_school_scoped(): void
    {
        $school = $this->createSchool();
        $applicant = $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $target = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $otherSchool = $this->createSchool();
        $foreignApplicant = $this->createApplicant($otherSchool, ['first_name' => 'Asha', 'last_name' => 'Verma']);
        $foreignYear = $this->createAcademicYear($otherSchool);
        $foreignCampus = $this->createCampus($otherSchool);
        $foreignGrade = $this->createGradeLevel($otherSchool);
        $this->createAdmissionApplication($foreignApplicant, $foreignYear, $foreignCampus, $foreignGrade);

        $byFirst = $this->withCtx($school, fn () => $this->readService()->directory(['applicant_name' => 'asha']));
        $byLast = $this->withCtx($school, fn () => $this->readService()->directory(['applicant_name' => 'VERMA']));

        $this->assertSame([$target->id], collect($byFirst->items())->pluck('id')->all(), 'Case-insensitive first-name match, School-scoped.');
        $this->assertSame([$target->id], collect($byLast->items())->pluck('id')->all(), 'Case-insensitive last-name match, School-scoped.');
    }

    // ==================================================================
    // E. Detail
    // ==================================================================

    #[Test]
    public function detail_resolves_a_same_school_application_with_expected_relationships(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['decision_note' => 'Internal note.']);

        $found = $this->withCtx($school, fn () => $this->readService()->detail($application->id));

        $this->assertNotNull($found);
        $this->assertSame($application->id, $found->id);
        $this->assertSame('Internal note.', $found->decision_note, 'decision_note IS present at detail level.');
        $this->assertTrue($found->relationLoaded('applicant'));
        $this->assertTrue($found->relationLoaded('academicYear'));
        $this->assertTrue($found->relationLoaded('campus'));
        $this->assertTrue($found->relationLoaded('gradeLevel'));
    }

    #[Test]
    public function detail_produces_the_same_outcome_for_a_foreign_school_application_and_a_random_uuid(): void
    {
        $schoolA = $this->createSchool();
        $otherSchool = $this->createSchool();
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($otherSchool);
        $foreignApplication = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $foreignResult = $this->withCtx($schoolA, fn () => $this->readService()->detail($foreignApplication->id));
        $randomResult = $this->withCtx($schoolA, fn () => $this->readService()->detail((string) Str::uuid()));

        $this->assertNull($foreignResult);
        $this->assertNull($randomResult);
    }

    // ==================================================================
    // F. Converted detail
    // ==================================================================

    #[Test]
    public function converted_detail_retains_applicant_academic_intent_and_conversion_provenance(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-READ-CONV-2']);
        $section = $this->createSection($year, $campus, $gradeLevel);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $application = $this->withCtx($school, fn () => AdmissionApplication::factory()->create([
            'school_id' => $applicant->school_id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $gradeLevel->id,
            'status' => 'converted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => now(),
        ]));

        // The Student's CURRENT status is irrelevant to the historical read.
        $this->withCtx($school, fn () => $student->update(['status' => 'inactive']));

        $found = $this->withCtx($school, fn () => $this->readService()->detail($application->id));

        $this->assertSame('converted', $found->status);
        $this->assertSame($applicant->id, $found->applicant->id);
        $this->assertSame($year->id, $found->academicYear->id);
        $this->assertSame($student->id, $found->convertedStudent->id);
        $this->assertSame($enrollment->id, $found->convertedStudentEnrollment->id);
    }

    // ==================================================================
    // G. No write side effects
    // ==================================================================

    #[Test]
    public function listing_searching_and_showing_never_mutate_state_or_write_audit_events(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $beforeUpdatedAt = $application->updated_at;
        $beforeAuditCount = $this->withCtx($school, fn () => SchoolAuditEvent::query()->count());

        $this->withCtx($school, function () use ($application) {
            $this->readService()->directory();
            $this->readService()->directory(['applicant_name' => 'zzz-no-match']);
            $this->readService()->detail($application->id);
        });

        $fresh = $this->withCtx($school, fn () => AdmissionApplication::query()->findOrFail($application->id));
        $this->assertTrue($beforeUpdatedAt->equalTo($fresh->updated_at));

        $afterAuditCount = $this->withCtx($school, fn () => SchoolAuditEvent::query()->count());
        $this->assertSame($beforeAuditCount, $afterAuditCount, 'Reads must never write audit events.');
    }

    // ==================================================================
    // H. N+1 prevention
    // ==================================================================

    #[Test]
    public function directory_does_not_issue_one_query_per_row(): void
    {
        $school = $this->createSchool();
        for ($i = 1; $i <= 10; $i++) {
            ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
            $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $page = $this->withCtx($school, fn () => $this->readService()->directory());
        foreach ($page->items() as $row) {
            $row->applicant->first_name;
            $row->academicYear->code;
            $row->campus->code;
            $row->gradeLevel->code;
        }

        // One count query + one page query + four eager-load queries
        // (applicant/academicYear/campus/gradeLevel) -- NOT one query
        // per row (10 rows would mean 40+ queries if eager-loading were
        // broken).
        $this->assertLessThan(15, $queryCount, 'Application directory must not perform one query per row.');
    }
}
