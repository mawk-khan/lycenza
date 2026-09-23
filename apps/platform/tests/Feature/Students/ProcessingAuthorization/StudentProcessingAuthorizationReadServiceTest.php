<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Domain\Students\Application\Exceptions\StudentNotAuthorizedForProcessingException;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

class StudentProcessingAuthorizationReadServiceTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    private function writeService(): StudentProcessingAuthorizationService
    {
        return app(StudentProcessingAuthorizationService::class);
    }

    private function readService(): StudentProcessingAuthorizationReadService
    {
        return app(StudentProcessingAuthorizationReadService::class);
    }

    #[Test]
    public function a_student_with_no_recorded_authorization_is_not_authorized(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);

        $this->assertFalse($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));

        $this->expectException(StudentNotAuthorizedForProcessingException::class);
        $this->readService()->assertAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords);
    }

    #[Test]
    public function a_valid_guardian_consent_authorizes_a_minor(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        $grant = $this->writeService()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);

        $this->assertTrue($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));
        $this->assertSame($grant->id, $this->readService()->qualifyingAuthorizationIdForStudent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));
    }

    #[Test]
    public function guardian_consent_does_not_qualify_for_an_adult_student(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        $this->writeService()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);

        $this->assertFalse($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));
    }

    #[Test]
    public function adult_student_consent_does_not_qualify_for_a_minor(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $actor = $this->staffActor($school);

        $this->writeService()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->assertFalse($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));
    }

    #[Test]
    public function statutory_school_purpose_qualifies_regardless_of_age(): void
    {
        $school = $this->createSchool();
        $minor = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $adult = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);

        $this->writeService()->recordStatutorySchoolPurpose($school, $minor, ProcessingAuthorizationPurpose::AcademicRecords, $actor);
        $this->writeService()->recordStatutorySchoolPurpose($school, $adult, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->assertTrue($this->readService()->isAuthorizedForProcessing($school, $minor, ProcessingAuthorizationPurpose::AcademicRecords));
        $this->assertTrue($this->readService()->isAuthorizedForProcessing($school, $adult, ProcessingAuthorizationPurpose::AcademicRecords));
    }

    #[Test]
    public function a_withdrawn_grant_no_longer_qualifies(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = $this->writeService()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->writeService()->withdraw($school, $grant, $actor);

        $this->assertFalse($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));
    }

    #[Test]
    public function withdrawing_one_basis_leaves_another_independent_qualifying_basis_active(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);

        $adultConsent = $this->writeService()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);
        $this->writeService()->recordStatutorySchoolPurpose($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->writeService()->withdraw($school, $adultConsent, $actor);

        $this->assertTrue($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));
    }

    #[Test]
    public function deterministic_provenance_prefers_the_most_recently_recorded_qualifying_grant(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);

        $this->writeService()->recordStatutorySchoolPurpose($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);
        $newer = $this->writeService()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->assertSame($newer->id, $this->readService()->qualifyingAuthorizationIdForStudent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));
    }

    #[Test]
    public function turning_18_stops_a_guardian_consent_lineage_from_qualifying_without_mutating_it(): void
    {
        $school = $this->createSchool(['timezone' => 'UTC']);
        $student = $this->createStudent($school, ['date_of_birth' => '2008-06-15']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        $grant = $this->writeService()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);

        $beforeBirthday = CarbonImmutable::parse('2026-06-14');
        $onBirthday = CarbonImmutable::parse('2026-06-15');

        $this->assertTrue($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $beforeBirthday));
        $this->assertFalse($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $onBirthday));

        // No mutation -- the same grant row, still `recorded`, still
        // referencing the same relationship; only the READ decision
        // changed. Re-fetched via the real School context, exactly
        // like every other tenant-scoped read in this suite.
        $refreshed = app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentProcessingAuthorization::query()->findOrFail($grant->id),
        );
        $this->assertSame(ProcessingAuthorizationBasisType::GuardianConsent, $refreshed->basis_type);
        $this->assertNull($refreshed->terminates_authorization_id);
    }

    #[Test]
    public function a_relationship_later_corrected_to_not_be_a_legal_guardian_stops_qualifying_a_historical_grant(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        $grant = $this->writeService()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);
        $this->assertTrue($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));

        app(TenantContext::class)->withSchool($school, fn () => $relationship->update(['is_legal_guardian' => false]));

        $this->assertFalse($this->readService()->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords));

        // Historical row untouched.
        $refreshed = app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentProcessingAuthorization::query()->findOrFail($grant->id),
        );
        $this->assertSame(ProcessingAuthorizationBasisType::GuardianConsent, $refreshed->basis_type);
        $this->assertNull($refreshed->terminates_authorization_id);
    }

    // lockQualifyingAuthorizationIdForProcessing()'s "must already be
    // inside a DB::transaction()" guard (LogicException otherwise) is
    // not exercisable from an ordinary test method here: this suite's
    // DatabaseTransactions harness itself wraps every test in an
    // already-open transaction, so DB::transactionLevel() can never
    // observe the "no open transaction" state this guard checks for.
    // The guard exists for real callers outside the test harness; it
    // is proven indirectly by every other test in this class only
    // ever calling the method from inside an explicit DB::transaction().

    #[Test]
    public function lock_qualifying_authorization_id_for_processing_returns_the_id_inside_a_transaction(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = $this->writeService()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $id = DB::transaction(
            fn () => $this->readService()->lockQualifyingAuthorizationIdForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords),
        );

        $this->assertSame($grant->id, $id);
    }

    /**
     * Phase 0H.4D-P2 lock-freshness correction §7: the closure audit's
     * confirmed defect -- lockQualifyingAuthorizationIdForProcessing()
     * locked a fresh Student row but then discarded it, continuing to
     * evaluate age against the CALLER's stale in-memory snapshot. This
     * is fully deterministic and needs no multi-process machinery: the
     * property under test ("does the method trust its own locked
     * re-fetch, or the caller's stale handle") is observable within a
     * single transaction by explicitly ordering the DOB correction
     * BEFORE the call, using a Student variable fetched BEFORE that
     * correction.
     *
     * Minor -> adult: a stale minor snapshot must not keep a
     * guardian_consent grant qualifying once the authoritative row
     * says adult; the method must instead surface the
     * adult_student_consent grant, which only the fresh row's age
     * makes eligible.
     */
    #[Test]
    public function the_lock_seam_uses_the_freshly_locked_student_row_not_a_stale_caller_snapshot_when_dob_moves_minor_to_adult(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']); // minor
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        // Grants for BOTH bases exist in the ledger at all times --
        // only current age determines which one currently qualifies.
        $guardianGrant = $this->writeService()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);
        $adultGrant = $this->writeService()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        // A STALE snapshot, fetched BEFORE the DOB correction below --
        // this is the exact object the corrected method must NOT trust
        // for the age decision.
        $staleMinorSnapshot = app(TenantContext::class)->withSchool($school, fn () => Student::query()->findOrFail($student->id));

        app(TenantContext::class)->withSchool($school, fn () => DB::table('students')->where('id', $student->id)->update(['date_of_birth' => '2000-01-01']));

        $id = DB::transaction(
            fn () => $this->readService()->lockQualifyingAuthorizationIdForProcessing($school, $staleMinorSnapshot, ProcessingAuthorizationPurpose::AcademicRecords),
        );

        $this->assertSame($adultGrant->id, $id, 'The lock seam must follow the freshly locked (now-adult) row, never the stale in-memory (minor) snapshot passed by the caller.');
        $this->assertNotSame($guardianGrant->id, $id);
    }

    /**
     * Inverse of the above: a stale ADULT snapshot must not keep an
     * adult_student_consent grant qualifying once the authoritative
     * row says minor; the method must surface the guardian_consent
     * grant instead.
     */
    #[Test]
    public function the_lock_seam_uses_the_freshly_locked_student_row_not_a_stale_caller_snapshot_when_dob_moves_adult_to_minor(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']); // adult
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        $guardianGrant = $this->writeService()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);
        $adultGrant = $this->writeService()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $staleAdultSnapshot = app(TenantContext::class)->withSchool($school, fn () => Student::query()->findOrFail($student->id));

        app(TenantContext::class)->withSchool($school, fn () => DB::table('students')->where('id', $student->id)->update(['date_of_birth' => '2012-01-01']));

        $id = DB::transaction(
            fn () => $this->readService()->lockQualifyingAuthorizationIdForProcessing($school, $staleAdultSnapshot, ProcessingAuthorizationPurpose::AcademicRecords),
        );

        $this->assertSame($guardianGrant->id, $id, 'The lock seam must follow the freshly locked (now-minor) row, never the stale in-memory (adult) snapshot passed by the caller.');
        $this->assertNotSame($adultGrant->id, $id);
    }
}
