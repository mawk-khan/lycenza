<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\Exceptions\AdmissionApplicationAlreadyConvertedException;
use App\Domain\Admissions\Application\Exceptions\AdmissionGuardianSelectionRequiredException;
use App\Domain\Admissions\Application\Exceptions\IncompatibleConversionSectionException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Application\GuardianConversionInstruction;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Guardians\Application\Exceptions\CrossSchoolRelationshipException;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.3: AdmissionConversionService's accepted -> converted
 * orchestration -- creation, preconditions, idempotency, atomicity, and
 * tenancy. Real-process concurrency is proven separately in
 * AdmissionConversionConcurrencyTest.php (this class cannot prove a
 * genuine race using DatabaseTransactions-wrapped fixtures shared by
 * only one PostgreSQL session).
 */
class AdmissionConversionServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): AdmissionConversionService
    {
        return app(AdmissionConversionService::class);
    }

    /**
     * @return array{school: School, applicant: Applicant, year: AcademicYear, campus: Campus, gradeLevel: GradeLevel, section: Section, application: AdmissionApplication}
     */
    private function buildAcceptedContext(?School $school = null, array $applicationAttributes = []): array
    {
        $school = $school ?? $this->createSchool();
        $applicant = $this->createApplicant($school, [
            'first_name' => 'Asha', 'middle_name' => 'K', 'last_name' => 'Verma', 'date_of_birth' => '2018-04-12',
        ]);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $gradeLevel);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, array_merge([
            'status' => 'accepted',
        ], $applicationAttributes));

        return compact('school', 'applicant', 'year', 'campus', 'gradeLevel', 'section', 'application');
    }

    private function fresh(School $school, string $id): AdmissionApplication
    {
        return app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::query()->findOrFail($id));
    }

    private function studentCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
    }

    private function enrollmentCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
    }

    private function guardianCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->count());
    }

    private function contactCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => GuardianContact::query()->count());
    }

    private function relationshipCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => StudentGuardianRelationship::query()->count());
    }

    private function auditCount(School $school, string $eventType): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->count(),
        );
    }

    // ==================================================================
    // A. Success core
    // ==================================================================

    #[Test]
    public function a_valid_conversion_creates_exactly_one_student_and_enrollment_and_converts_the_application(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();

        $result = $this->service()->convert($application, 'S-1001', $section, '01', '2026-06-01');

        $this->assertSame('converted', $result->application->status);
        $this->assertSame($result->student->id, $result->application->converted_student_id);
        $this->assertSame($result->enrollment->id, $result->application->converted_student_enrollment_id);
        $this->assertNotNull($result->application->converted_at);
        $this->assertSame(1, $this->studentCount($school));
        $this->assertSame(1, $this->enrollmentCount($school));

        $freshApplicant = app(TenantContext::class)->withSchool($school, fn () => $applicant->fresh());
        $this->assertSame('Asha', $freshApplicant->first_name);
        $this->assertSame('Verma', $freshApplicant->last_name);
    }

    #[Test]
    public function student_identity_fields_match_the_applicant_exactly(): void
    {
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext();

        $result = $this->service()->convert($application, 'S-1002', $section, '02', '2026-06-01');

        $this->assertSame('Asha', $result->student->first_name);
        $this->assertSame('K', $result->student->middle_name);
        $this->assertSame('Verma', $result->student->last_name);
        $this->assertSame('2018-04-12', $result->student->date_of_birth->toDateString());
    }

    #[Test]
    public function enrollment_academic_context_matches_the_application_and_selected_section(): void
    {
        ['year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();

        $result = $this->service()->convert($application, 'S-1003', $section, '03', '2026-06-01');

        $this->assertSame($year->id, $result->enrollment->academic_year_id);
        $this->assertSame($campus->id, $result->enrollment->campus_id);
        $this->assertSame($gradeLevel->id, $result->enrollment->grade_level_id);
        $this->assertSame($section->id, $result->enrollment->section_id);
        $this->assertSame($application->school_id, $result->enrollment->school_id);
    }

    #[Test]
    public function conversion_via_the_full_1d2_lifecycle_pipeline_succeeds(): void
    {
        $school = $this->createSchool();
        $applicant = $this->createApplicant($school, ['first_name' => 'Rohit', 'date_of_birth' => '2017-01-01']);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $gradeLevel);

        $lifecycle = app(AdmissionApplicationService::class);
        $application = $lifecycle->create($applicant, $year, $campus, $gradeLevel);
        $application = $lifecycle->submit($application);
        $application = $lifecycle->accept($application, 'Strong interview.');

        $result = $this->service()->convert($application, 'S-PIPE-1', $section, '01', '2026-06-01');

        $this->assertSame('converted', $result->application->status);
        $this->assertSame('Rohit', $result->student->first_name);
    }

    #[Test]
    public function the_conversion_success_audit_carries_only_id_metadata(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();

        $result = $this->service()->convert($application, 'S-1004', $section, '04', '2026-06-01');

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'admission_application.converted')->firstOrFail(),
        );

        $this->assertSame($result->student->id, $event->metadata['studentId']);
        $this->assertSame($result->enrollment->id, $event->metadata['studentEnrollmentId']);
        $this->assertSame($year->id, $event->metadata['academicYearId']);
        $this->assertSame($campus->id, $event->metadata['campusId']);
        $this->assertSame($gradeLevel->id, $event->metadata['gradeLevelId']);

        $haystack = strtolower(json_encode($event->metadata));
        $this->assertStringNotContainsString('asha', $haystack);
        $this->assertStringNotContainsString('verma', $haystack);
        $this->assertStringNotContainsString('s-1004', $haystack);
        $this->assertStringNotContainsString('interview', $haystack);
        $this->assertArrayNotHasKey('rollNumber', $event->metadata);
        $this->assertArrayNotHasKey('studentNumber', $event->metadata);
        $this->assertArrayNotHasKey('decisionNote', $event->metadata);
    }

    // ==================================================================
    // B. Only accepted
    // ==================================================================

    #[Test]
    public function conversion_from_every_non_accepted_status_is_rejected_without_side_effects(): void
    {
        $school = $this->createSchool();

        foreach (['draft', 'submitted', 'rejected', 'withdrawn'] as $status) {
            ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school, ['status' => $status]);

            try {
                $this->service()->convert($application, 'S-'.Str::random(8), $section, '01', '2026-06-01');
                $this->fail("Expected InvalidAdmissionApplicationTransitionException for status={$status}.");
            } catch (InvalidAdmissionApplicationTransitionException) {
                // expected
            }

            $fresh = $this->fresh($school, $application->id);
            $this->assertSame($status, $fresh->status);
            $this->assertNull($fresh->converted_student_id);
        }

        $this->assertSame(0, $this->studentCount($school));
        $this->assertSame(0, $this->enrollmentCount($school));
        $this->assertSame(0, $this->auditCount($school, 'admission_application.converted'));
    }

    // ==================================================================
    // C. Already converted
    // ==================================================================

    #[Test]
    public function converting_an_already_converted_application_is_rejected_without_creating_a_second_student(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();
        $first = $this->service()->convert($application, 'S-2001', $section, '01', '2026-06-01');

        $this->assertSame(1, $this->studentCount($school));
        $this->assertSame(1, $this->enrollmentCount($school));

        try {
            $this->service()->convert($this->fresh($school, $application->id), 'S-2002', $section, '02', '2026-06-01');
            $this->fail('Expected AdmissionApplicationAlreadyConvertedException.');
        } catch (AdmissionApplicationAlreadyConvertedException) {
            // expected
        }

        $this->assertSame(1, $this->studentCount($school));
        $this->assertSame(1, $this->enrollmentCount($school));
        $this->assertSame(1, $this->auditCount($school, 'admission_application.converted'));

        $fresh = $this->fresh($school, $application->id);
        $this->assertSame($first->student->id, $fresh->converted_student_id);
    }

    // ==================================================================
    // D. Stale accepted object
    // ==================================================================

    #[Test]
    public function a_stale_in_memory_accepted_application_is_rejected_once_the_real_row_has_moved_on(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();
        $staleApplication = $this->fresh($school, $application->id);

        // A separate, fresh reference withdraws the application --
        // $staleApplication's in-memory ->status still reads 'accepted'.
        app(AdmissionApplicationService::class)->withdraw($this->fresh($school, $application->id));
        $this->assertSame('accepted', $staleApplication->status, 'Sanity check: the in-memory model must still be stale.');

        $this->expectException(InvalidAdmissionApplicationTransitionException::class);
        $this->service()->convert($staleApplication, 'S-3001', $section, '01', '2026-06-01');
    }

    // ==================================================================
    // E. Forced-failure atomicity (MANDATORY)
    // ==================================================================

    /**
     * Forces a REAL, deterministic failure at the LAST composed step
     * (StudentEnrollmentService::enroll()'s own
     * `student_enrollments_school_id_academic_year_id_section_id_roll_`
     * unique constraint, via DuplicateEnrollmentRollNumberException) --
     * not an artificial test hook -- after Student, Guardian,
     * GuardianContact, and StudentGuardianRelationship have all already
     * been written earlier in the SAME outer transaction. Proves the
     * entire composition (Admissions' own outer DB::transaction() plus
     * every composed service's own inner DB::transaction()) rolls back
     * as one atomic unit -- including every nested canonical success
     * audit row, which would otherwise falsely represent Student/
     * Guardian/relationship creation that never really happened.
     */
    #[Test]
    public function a_forced_failure_at_the_final_enrollment_step_rolls_back_every_earlier_canonical_write(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();

        // Pre-occupy roll number '09' in the target Section with an
        // unrelated Student -- this is what makes the LAST composed
        // step (enroll()) fail deterministically via a real database
        // constraint, after Student/Guardian/Contact/Relationship have
        // already been created inside this conversion's transaction.
        // Created through the REAL services (not the raw factory
        // fixture helpers) specifically so a genuine baseline
        // student.created/student_enrollment.created audit event
        // exists below to prove the conversion's OWN attempt added no
        // second one.
        $otherStudent = app(StudentService::class)->create($school, [
            'student_number' => 'S-OTHER', 'first_name' => 'Other', 'date_of_birth' => '2016-01-01',
        ]);
        app(StudentEnrollmentService::class)->enroll($otherStudent, $section, '09', '2026-06-01');

        $guardianInstruction = GuardianConversionInstruction::create(
            firstName: 'Priya',
            middleName: null,
            lastName: 'Verma',
            relationshipType: RelationshipType::Mother,
            contactType: ContactType::Email,
            contactValue: 'priya.verma@example.com',
        );

        try {
            $this->service()->convert($application, 'S-4001', $section, '09', '2026-06-01', $guardianInstruction);
            $this->fail('Expected DuplicateEnrollmentRollNumberException.');
        } catch (DuplicateEnrollmentRollNumberException) {
            // expected -- the real forced failure
        }

        // Applicant/Application unchanged.
        $freshApplication = $this->fresh($school, $application->id);
        $this->assertSame('accepted', $freshApplication->status);
        $this->assertNull($freshApplication->converted_student_id);
        $this->assertNull($freshApplication->converted_student_enrollment_id);
        $this->assertNull($freshApplication->converted_at);

        // Only the ONE pre-existing (unrelated) Student/Enrollment
        // survive -- the conversion's own Student/Guardian/Enrollment
        // never persisted.
        $this->assertSame(1, $this->studentCount($school), 'Only the pre-existing unrelated Student may remain.');
        $this->assertSame(1, $this->enrollmentCount($school), 'Only the pre-existing unrelated Enrollment may remain.');
        $this->assertSame(0, $this->guardianCount($school), 'The conversion-created Guardian must not survive.');
        $this->assertSame(0, $this->contactCount($school), 'The conversion-created GuardianContact must not survive.');
        $this->assertSame(0, $this->relationshipCount($school), 'The conversion-created relationship must not survive.');

        // No false success audit for ANY nested canonical operation,
        // nor for the conversion itself.
        $this->assertSame(0, $this->auditCount($school, 'admission_application.converted'));
        $this->assertSame(0, $this->auditCount($school, 'guardian.created'));
        $this->assertSame(0, $this->auditCount($school, 'guardian_contact.added'));
        $this->assertSame(0, $this->auditCount($school, 'student_guardian.linked'));

        // Exactly ONE student.created/student_enrollment.created audit
        // survives -- the pre-existing unrelated fixture's, created
        // via the real services BEFORE the conversion attempt. If the
        // conversion's own Student/Enrollment writes had left a false
        // success audit behind despite the transaction rollback, this
        // count would be 2.
        $this->assertSame(1, $this->auditCount($school, 'student.created'));
        $this->assertSame(1, $this->auditCount($school, 'student_enrollment.created'));
    }

    // ==================================================================
    // G. Guardian paths
    // ==================================================================

    #[Test]
    public function conversion_with_no_guardian_instruction_creates_zero_guardian_side_effects(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();

        $result = $this->service()->convert($application, 'S-5001', $section, '01', '2026-06-01');

        $this->assertNull($result->guardian);
        $this->assertNull($result->relationship);
        $this->assertSame(0, $this->guardianCount($school));
        $this->assertSame(0, $this->relationshipCount($school));
    }

    #[Test]
    public function conversion_with_a_new_guardian_creates_guardian_contact_and_relationship_with_no_admissions_contact_copy(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();

        $guardianInstruction = GuardianConversionInstruction::create(
            firstName: 'Priya',
            middleName: null,
            lastName: 'Verma',
            relationshipType: RelationshipType::Mother,
            contactType: ContactType::Email,
            contactValue: 'priya.verma+new@example.com',
            isLegalGuardian: true,
        );

        $result = $this->service()->convert($application, 'S-5002', $section, '02', '2026-06-01', $guardianInstruction);

        $this->assertNotNull($result->guardian);
        $this->assertSame('Priya', $result->guardian->first_name);
        $this->assertNotNull($result->relationship);
        $this->assertSame(RelationshipType::Mother, $result->relationship->relationship_type);
        $this->assertTrue($result->relationship->is_legal_guardian);
        $this->assertSame(1, $this->guardianCount($school));
        $this->assertSame(1, $this->contactCount($school));
        $this->assertSame(1, $this->relationshipCount($school));

        // Admissions itself persists no contact copy -- schema-level
        // guarantee already proven at 1D.1, re-asserted here at the
        // service boundary: the Applicant/AdmissionApplication rows
        // have no email/phone columns to have written into.
        $this->assertFalse(Schema::hasColumn('applicants', 'email'));
        $this->assertFalse(Schema::hasColumn('admission_applications', 'email'));
    }

    #[Test]
    public function conversion_linking_an_existing_same_school_guardian_creates_no_new_guardian(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();
        $existingGuardian = $this->createGuardian($school, ['first_name' => 'Priya', 'last_name' => 'Verma']);

        $guardianInstruction = GuardianConversionInstruction::linkExisting($existingGuardian, RelationshipType::Mother);

        $result = $this->service()->convert($application, 'S-5003', $section, '03', '2026-06-01', $guardianInstruction);

        $this->assertSame($existingGuardian->id, $result->guardian->id);
        $this->assertSame(1, $this->guardianCount($school), 'No new Guardian should have been created.');
        $this->assertSame(0, $this->contactCount($school));
        $this->assertSame(1, $this->relationshipCount($school));
    }

    #[Test]
    public function linking_a_foreign_school_guardian_is_rejected_and_leaves_no_student_behind(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();
        $otherSchool = $this->createSchool();
        $foreignGuardian = $this->createGuardian($otherSchool);

        $guardianInstruction = GuardianConversionInstruction::linkExisting($foreignGuardian, RelationshipType::Mother);

        try {
            $this->service()->convert($application, 'S-5004', $section, '04', '2026-06-01', $guardianInstruction);
            $this->fail('Expected CrossSchoolRelationshipException.');
        } catch (CrossSchoolRelationshipException) {
            // expected
        }

        $this->assertSame(0, $this->studentCount($school), 'The Student created before the relationship-link failure must not survive rollback.');
        $this->assertSame(0, $this->relationshipCount($school));
        $fresh = $this->fresh($school, $application->id);
        $this->assertSame('accepted', $fresh->status);
    }

    #[Test]
    public function creating_a_guardian_whose_contact_already_matches_an_existing_guardian_requires_explicit_selection(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();
        $existingGuardian = $this->createGuardian($school, ['first_name' => 'Priya']);
        $this->createGuardianContact($existingGuardian, ContactType::Email, 'shared.contact@example.com');

        $guardianInstruction = GuardianConversionInstruction::create(
            firstName: 'Priya',
            middleName: null,
            lastName: 'Verma',
            relationshipType: RelationshipType::Mother,
            contactType: ContactType::Email,
            contactValue: 'shared.contact@example.com',
        );

        try {
            $this->service()->convert($application, 'S-5005', $section, '05', '2026-06-01', $guardianInstruction);
            $this->fail('Expected AdmissionGuardianSelectionRequiredException.');
        } catch (AdmissionGuardianSelectionRequiredException $e) {
            $this->assertSame(1, $e->candidateCount);
        }

        // No blind duplicate Guardian created; no partial canonical
        // state survives (the whole transaction, including the earlier
        // Student write, rolled back).
        $this->assertSame(1, $this->guardianCount($school), 'Only the pre-existing candidate Guardian may remain.');
        $this->assertSame(0, $this->studentCount($school));
    }

    // ==================================================================
    // H. Cross-School / Section compatibility
    // ==================================================================

    #[Test]
    public function converting_a_foreign_schools_application_is_structurally_unreachable_via_the_ordinary_tenant_scoped_query(): void
    {
        $schoolB = $this->createSchool();
        ['application' => $applicationB] = $this->buildAcceptedContext($schoolB);

        $visibleFromSchoolA = app(TenantContext::class)->withSchool(
            $this->createSchool(),
            fn () => AdmissionApplication::query()->find($applicationB->id),
        );

        $this->assertNull($visibleFromSchoolA, 'School A must never be able to load School B\'s AdmissionApplication to pass into the conversion service in the first place.');
    }

    #[Test]
    public function a_cross_school_section_is_rejected_before_any_canonical_record_is_created(): void
    {
        ['application' => $application] = $this->buildAcceptedContext();
        $otherSchool = $this->createSchool();
        $otherYear = $this->createAcademicYear($otherSchool);
        $otherCampus = $this->createCampus($otherSchool);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $foreignSection = $this->createSection($otherYear, $otherCampus, $otherGrade);

        $this->expectException(IncompatibleConversionSectionException::class);
        $this->service()->convert($application, 'S-6001', $foreignSection, '01', '2026-06-01');
    }

    #[Test]
    public function a_section_from_the_wrong_academic_year_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'gradeLevel' => $gradeLevel, 'application' => $application] = $this->buildAcceptedContext();
        $wrongYear = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $wrongSection = $this->createSection($wrongYear, $campus, $gradeLevel);

        $this->expectException(IncompatibleConversionSectionException::class);
        $this->service()->convert($application, 'S-6002', $wrongSection, '01', '2026-06-01');
    }

    #[Test]
    public function a_section_from_the_wrong_campus_is_rejected(): void
    {
        ['school' => $school, 'year' => $year, 'gradeLevel' => $gradeLevel, 'application' => $application] = $this->buildAcceptedContext();
        $wrongCampus = $this->createCampus($school);
        $wrongSection = $this->createSection($year, $wrongCampus, $gradeLevel);

        $this->expectException(IncompatibleConversionSectionException::class);
        $this->service()->convert($application, 'S-6003', $wrongSection, '01', '2026-06-01');
    }

    #[Test]
    public function a_section_from_the_wrong_grade_level_is_rejected(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'application' => $application] = $this->buildAcceptedContext();
        $wrongGrade = $this->createGradeLevel($school);
        $wrongSection = $this->createSection($year, $campus, $wrongGrade);

        $this->expectException(IncompatibleConversionSectionException::class);
        $this->service()->convert($application, 'S-6004', $wrongSection, '01', '2026-06-01');
    }

    // ==================================================================
    // I. Invalid Student Number
    // ==================================================================

    #[Test]
    public function a_colliding_student_number_is_rejected_cleanly_without_sqlstate_exposure(): void
    {
        ['school' => $school, 'section' => $section, 'application' => $application] = $this->buildAcceptedContext();
        $this->createStudent($school, ['student_number' => 'S-DUP']);

        try {
            $this->service()->convert($application, 'S-DUP', $section, '01', '2026-06-01');
            $this->fail('Expected DuplicateStudentNumberException.');
        } catch (DuplicateStudentNumberException $e) {
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        $fresh = $this->fresh($school, $application->id);
        $this->assertSame('accepted', $fresh->status);
        $this->assertNull($fresh->converted_student_id);
        $this->assertSame(1, $this->studentCount($school), 'Only the pre-existing colliding Student may remain.');
        $this->assertSame(0, $this->enrollmentCount($school));
        $this->assertSame(0, $this->auditCount($school, 'admission_application.converted'));
    }
}
