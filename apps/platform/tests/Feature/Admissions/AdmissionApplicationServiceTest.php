<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\Exceptions\CrossSchoolAdmissionReferenceException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Application\Exceptions\OpenAdmissionApplicationExistsException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.2: the only sanctioned write path for AdmissionApplication
 * creation and lifecycle transitions (draft -> submitted ->
 * accepted/rejected/withdrawn, accepted -> withdrawn). Never exercises
 * accepted -> converted -- that transition belongs to a future Phase
 * 1D.3 conversion command. See
 * docs/admissions/PHASE-1D-2-APPLICATION-LIFECYCLE.md.
 */
class AdmissionApplicationServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): AdmissionApplicationService
    {
        return app(AdmissionApplicationService::class);
    }

    /**
     * @return array{school: School, applicant: Applicant, year: AcademicYear, campus: Campus, gradeLevel: GradeLevel}
     */
    private function buildContext(?School $school = null): array
    {
        $school = $school ?? $this->createSchool();
        $applicant = $this->createApplicant($school);
        // A random, School-unique code each call -- buildContext() may
        // be invoked several times against the SAME $school (e.g. the
        // illegal-transition matrix test below), and AcademicYear.code
        // is unique per School (academic_years_school_id_code_unique).
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);

        return compact('school', 'applicant', 'year', 'campus', 'gradeLevel');
    }

    private function auditCount(School $school, string $eventType): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->count(),
        );
    }

    private function freshApplication(School $school, string $id): AdmissionApplication
    {
        return app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::query()->findOrFail($id));
    }

    // ==================================================================
    // A. Creation
    // ==================================================================

    #[Test]
    public function create_writes_a_draft_application_in_the_same_school_with_correct_academic_context(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();

        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $this->assertSame('draft', $application->status);
        $this->assertSame($school->id, $application->school_id);
        $this->assertSame($applicant->id, $application->applicant_id);
        $this->assertSame($year->id, $application->academic_year_id);
        $this->assertSame($campus->id, $application->campus_id);
        $this->assertSame($gradeLevel->id, $application->grade_level_id);
        $this->assertNull($application->converted_student_id);
        $this->assertNull($application->converted_student_enrollment_id);
        $this->assertNull($application->converted_at);
    }

    #[Test]
    public function create_writes_a_success_audit_event_with_context_ids_only(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();

        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'admission_application.created')->firstOrFail(),
        );

        $this->assertSame($application->id, $event->subject_id);
        $this->assertSame($applicant->id, $event->metadata['applicantId']);
        $this->assertSame($year->id, $event->metadata['academicYearId']);
        $this->assertSame($campus->id, $event->metadata['campusId']);
        $this->assertSame($gradeLevel->id, $event->metadata['gradeLevelId']);
    }

    #[Test]
    public function create_cross_school_academic_year_is_rejected_by_a_clean_domain_exception(): void
    {
        ['applicant' => $applicant, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $yearOther = $this->createAcademicYear($otherSchool);

        $this->expectException(CrossSchoolAdmissionReferenceException::class);
        $this->service()->create($applicant, $yearOther, $campus, $gradeLevel);
    }

    #[Test]
    public function create_cross_school_campus_is_rejected_by_a_clean_domain_exception(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $campusOther = $this->createCampus($otherSchool);

        $this->expectException(CrossSchoolAdmissionReferenceException::class);
        $this->service()->create($applicant, $year, $campusOther, $gradeLevel);
    }

    #[Test]
    public function create_cross_school_grade_level_is_rejected_by_a_clean_domain_exception(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $gradeLevelOther = $this->createGradeLevel($otherSchool);

        $this->expectException(CrossSchoolAdmissionReferenceException::class);
        $this->service()->create($applicant, $year, $campus, $gradeLevelOther);
    }

    #[Test]
    public function create_cross_school_applicant_is_rejected_by_a_clean_domain_exception(): void
    {
        $otherSchool = $this->createSchool();
        $applicantOther = $this->createApplicant($otherSchool);
        ['year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();

        $this->expectException(CrossSchoolAdmissionReferenceException::class);
        $this->service()->create($applicantOther, $year, $campus, $gradeLevel);
    }

    #[Test]
    public function a_cross_school_reference_never_leaves_a_partially_committed_row_or_a_false_success_audit(): void
    {
        ['applicant' => $applicant, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $yearOther = $this->createAcademicYear($otherSchool);

        try {
            $this->service()->create($applicant, $yearOther, $campus, $gradeLevel);
        } catch (CrossSchoolAdmissionReferenceException) {
            // expected
        }

        $this->assertSame(0, $this->auditCount($school, 'admission_application.created'));
        $count = app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::query()->count());
        $this->assertSame(0, $count);
    }

    // ==================================================================
    // B. Open-application conflict / reapplication
    // ==================================================================

    #[Test]
    public function a_second_simultaneously_open_application_for_the_same_context_is_a_clean_domain_conflict(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $this->expectException(OpenAdmissionApplicationExistsException::class);
        $this->service()->create($applicant, $year, $campus, $gradeLevel);
    }

    #[Test]
    public function the_open_conflict_exception_never_exposes_a_raw_sql_state(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $this->service()->create($applicant, $year, $campus, $gradeLevel);

        try {
            $this->service()->create($applicant, $year, $campus, $gradeLevel);
            $this->fail('Expected OpenAdmissionApplicationExistsException was not thrown.');
        } catch (OpenAdmissionApplicationExistsException $e) {
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
            $this->assertStringNotContainsString('duplicate key', $e->getMessage());
        }
    }

    #[Test]
    public function reapplication_is_allowed_after_the_prior_application_is_rejected(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $first = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $this->service()->submit($this->freshApplication($school, $first->id));
        $this->service()->reject($this->freshApplication($school, $first->id));

        $second = $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $this->assertSame('draft', $second->status);
        $this->assertNotSame($first->id, $second->id);
    }

    #[Test]
    public function reapplication_is_allowed_after_the_prior_application_is_withdrawn(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $first = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $this->service()->submit($this->freshApplication($school, $first->id));
        $this->service()->withdraw($this->freshApplication($school, $first->id));

        $second = $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $this->assertSame('draft', $second->status);
    }

    #[Test]
    public function reapplication_is_allowed_alongside_a_converted_application_via_factory_fixture(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-CONV-1']);
        $section = $this->createSection($year, $campus, $gradeLevel);
        $enrollment = $this->createStudentEnrollment($student, $section);

        app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::factory()->create([
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

        $second = $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $this->assertSame('draft', $second->status);
    }

    #[Test]
    public function the_same_applicant_may_open_a_second_application_in_a_different_academic_year(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $yearTwo = $this->createAcademicYear($school, ['code' => 'AY-2', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $second = $this->service()->create($applicant, $yearTwo, $campus, $gradeLevel);

        $this->assertSame('draft', $second->status);
    }

    // ==================================================================
    // C. Legal lifecycle transitions
    // ==================================================================

    #[Test]
    public function submit_transitions_draft_to_submitted_and_audits(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $submitted = $this->service()->submit($application);

        $this->assertSame('submitted', $submitted->status);
        $this->assertNull($submitted->converted_student_id);
        $this->assertSame(1, $this->auditCount($school, 'admission_application.submitted'));
    }

    #[Test]
    public function accept_transitions_submitted_to_accepted_and_audits_without_creating_a_student(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $application = $this->service()->submit($application);

        $accepted = $this->service()->accept($application, 'Strong interview.');

        $this->assertSame('accepted', $accepted->status);
        $this->assertSame('Strong interview.', $accepted->decision_note);
        $this->assertNull($accepted->converted_student_id);
        $this->assertNull($accepted->converted_at);
        $this->assertSame(1, $this->auditCount($school, 'admission_application.accepted'));

        $studentCount = app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
        $this->assertSame(0, $studentCount);
    }

    #[Test]
    public function reject_transitions_submitted_to_rejected_and_the_audit_excludes_the_decision_note(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $application = $this->service()->submit($application);

        $rejected = $this->service()->reject($application, 'Grade level not available.');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Grade level not available.', $rejected->decision_note);

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'admission_application.rejected')->firstOrFail(),
        );
        $haystack = strtolower(json_encode($event->metadata ?? []));
        $this->assertStringNotContainsString('grade level not available', $haystack);

        $studentCount = app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
        $this->assertSame(0, $studentCount);
    }

    #[Test]
    public function a_blank_decision_note_is_normalized_to_null(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext();
        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $application = $this->service()->submit($application);

        $rejected = $this->service()->reject($application, '   ');

        $this->assertNull($rejected->decision_note);
    }

    #[Test]
    public function withdraw_transitions_submitted_to_withdrawn_and_history_persists(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $application = $this->service()->submit($application);

        $withdrawn = $this->service()->withdraw($application);

        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertSame(1, $this->auditCount($school, 'admission_application.withdrawn'));

        $stillExists = app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::query()->find($withdrawn->id));
        $this->assertNotNull($stillExists, 'Withdrawn applications are never hard-deleted.');
    }

    #[Test]
    public function withdraw_transitions_accepted_to_withdrawn_and_preserves_the_decision_note(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $application = $this->service()->submit($application);
        $application = $this->service()->accept($application, 'Approved by committee.');

        $withdrawn = $this->service()->withdraw($application);

        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertSame('Approved by committee.', $withdrawn->decision_note, 'An accepted application\'s decision note must survive a later withdrawal unchanged.');
        $this->assertSame(1, $this->auditCount($school, 'admission_application.withdrawn'));
    }

    // ==================================================================
    // D. Illegal transition matrix
    // ==================================================================

    #[Test]
    public function every_prohibited_transition_is_rejected_with_a_deterministic_lifecycle_exception(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();

        $draft = $this->service()->create($applicant, $year, $campus, $gradeLevel);

        $submittedContext = $this->buildContext($school);
        $submitted = $this->service()->create($submittedContext['applicant'], $submittedContext['year'], $submittedContext['campus'], $submittedContext['gradeLevel']);
        $submitted = $this->service()->submit($submitted);

        $acceptedContext = $this->buildContext($school);
        $accepted = $this->service()->create($acceptedContext['applicant'], $acceptedContext['year'], $acceptedContext['campus'], $acceptedContext['gradeLevel']);
        $accepted = $this->service()->submit($accepted);
        $accepted = $this->service()->accept($accepted);

        $rejectedContext = $this->buildContext($school);
        $rejected = $this->service()->create($rejectedContext['applicant'], $rejectedContext['year'], $rejectedContext['campus'], $rejectedContext['gradeLevel']);
        $rejected = $this->service()->submit($rejected);
        $rejected = $this->service()->reject($rejected);

        $withdrawnContext = $this->buildContext($school);
        $withdrawn = $this->service()->create($withdrawnContext['applicant'], $withdrawnContext['year'], $withdrawnContext['campus'], $withdrawnContext['gradeLevel']);
        $withdrawn = $this->service()->submit($withdrawn);
        $withdrawn = $this->service()->withdraw($withdrawn);

        $convertedContext = $this->buildContext($school);
        $convertedStudent = $this->createStudent($school, ['student_number' => 'S-CONV-MATRIX']);
        $convertedSection = $this->createSection($convertedContext['year'], $convertedContext['campus'], $convertedContext['gradeLevel']);
        $convertedEnrollment = $this->createStudentEnrollment($convertedStudent, $convertedSection);
        $converted = app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::factory()->create([
            'school_id' => $convertedContext['applicant']->school_id,
            'applicant_id' => $convertedContext['applicant']->id,
            'academic_year_id' => $convertedContext['year']->id,
            'campus_id' => $convertedContext['campus']->id,
            'grade_level_id' => $convertedContext['gradeLevel']->id,
            'status' => 'converted',
            'converted_student_id' => $convertedStudent->id,
            'converted_student_enrollment_id' => $convertedEnrollment->id,
            'converted_at' => now(),
        ]));

        /** @var array<int, array{0: AdmissionApplication, 1: string, 2: string}> $cases */
        $cases = [
            [$draft, 'accept', 'draft->accept'],
            [$draft, 'reject', 'draft->reject'],
            [$draft, 'withdraw', 'draft->withdraw'],
            [$submitted, 'submit', 'submitted->submit'],
            [$accepted, 'submit', 'accepted->submit'],
            [$accepted, 'reject', 'accepted->reject'],
            [$rejected, 'submit', 'rejected->submit'],
            [$rejected, 'accept', 'rejected->accept'],
            [$rejected, 'reject', 'rejected->reject'],
            [$rejected, 'withdraw', 'rejected->withdraw'],
            [$withdrawn, 'submit', 'withdrawn->submit'],
            [$withdrawn, 'accept', 'withdrawn->accept'],
            [$withdrawn, 'reject', 'withdrawn->reject'],
            [$withdrawn, 'withdraw', 'withdrawn->withdraw'],
            [$converted, 'submit', 'converted->submit'],
            [$converted, 'accept', 'converted->accept'],
            [$converted, 'reject', 'converted->reject'],
            [$converted, 'withdraw', 'converted->withdraw'],
        ];

        foreach ($cases as [$application, $method, $label]) {
            try {
                $this->service()->$method($this->freshApplication($school, $application->id));
                $this->fail("Expected InvalidAdmissionApplicationTransitionException for {$label}.");
            } catch (InvalidAdmissionApplicationTransitionException) {
                // expected
            }
        }

        // A converted application's provenance is never touched by any
        // of the rejected attempts above.
        $freshConverted = $this->freshApplication($school, $converted->id);
        $this->assertSame('converted', $freshConverted->status);
        $this->assertSame($convertedStudent->id, $freshConverted->converted_student_id);
        $this->assertSame($convertedEnrollment->id, $freshConverted->converted_student_enrollment_id);
        $this->assertNotNull($freshConverted->converted_at);
    }

    // ==================================================================
    // E. Stale-model / lock protection
    // ==================================================================

    #[Test]
    public function the_service_reloads_the_authoritative_row_rather_than_trusting_a_stale_in_memory_status(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'school' => $school] = $this->buildContext();
        $application = $this->service()->create($applicant, $year, $campus, $gradeLevel);
        $application = $this->service()->submit($application);

        $staleApplication = $this->freshApplication($school, $application->id);

        // A separate call, using a FRESH reference, rejects the
        // application -- $staleApplication's in-memory ->status still
        // reads 'submitted'.
        $this->service()->reject($this->freshApplication($school, $application->id));
        $this->assertSame('submitted', $staleApplication->status, 'Sanity check: the in-memory model must still be stale.');

        // If the service trusted $staleApplication->status instead of
        // reloading+locking the authoritative row, this would
        // incorrectly succeed (submitted -> accepted is otherwise
        // legal) despite the real row already being 'rejected'.
        $this->expectException(InvalidAdmissionApplicationTransitionException::class);
        $this->service()->accept($staleApplication);
    }

    // ==================================================================
    // F. Cross-School mutation / leakage
    // ==================================================================

    #[Test]
    public function a_school_cannot_read_another_schools_admission_application_via_the_ordinary_tenant_scoped_query(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        ['applicant' => $applicantB, 'year' => $yearB, 'campus' => $campusB, 'gradeLevel' => $gradeLevelB] = $this->buildContext($schoolB);
        $applicationB = $this->service()->create($applicantB, $yearB, $campusB, $gradeLevelB);

        $visibleFromSchoolA = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => AdmissionApplication::query()->find($applicationB->id),
        );

        $this->assertNull($visibleFromSchoolA, 'School A must never be able to load School B\'s AdmissionApplication through the ordinary tenant-scoped query -- there is no legitimate path for School A\'s actor to obtain this row to pass into the service in the first place.');
    }
}
