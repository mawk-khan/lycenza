<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Domain\Students\Application\Exceptions\GuardianRelationshipNotEligibleException;
use App\Domain\Students\Application\Exceptions\ProcessingAuthorizationAlreadyTerminatedException;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Domain\ProcessingAuthorizationStatus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

class StudentProcessingAuthorizationServiceTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    private function service(): StudentProcessingAuthorizationService
    {
        return app(StudentProcessingAuthorizationService::class);
    }

    /**
     * RLS on school_audit_events is enforced at the database level
     * regardless of Eloquent scopes -- a verification query run
     * outside an active TenantContext (the normal state between
     * assertions in a test) is filtered to zero rows by the RLS
     * policy itself, not merely by SchoolScope. Wrapping in
     * withSchool() sets the real Postgres session variable the
     * policy reads, exactly like every production code path already
     * does.
     */
    private function auditEventCount(School $school, string $eventType): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', $eventType)->count(),
        );
    }

    private function auditEvent(School $school, string $eventType): SchoolAuditEvent
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', $eventType)->firstOrFail(),
        );
    }

    #[Test]
    public function guardian_consent_is_recorded_and_audited(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        $grant = $this->service()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor, 'recorded per admission form');

        $this->assertSame(ProcessingAuthorizationBasisType::GuardianConsent, $grant->basis_type);
        $this->assertSame(ProcessingAuthorizationStatus::Recorded, $grant->status);
        $this->assertSame($relationship->id, $grant->student_guardian_relationship_id);
        $this->assertNull($grant->terminates_authorization_id);
        $this->assertSame($actor->id, $grant->recorded_by_user_id);

        $this->assertSame(1, $this->auditEventCount($school, 'students.processing_authorization.recorded'));
    }

    #[Test]
    public function guardian_consent_is_refused_when_the_relationship_is_not_a_legal_guardian(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => false, 'is_emergency_contact' => true]);
        $actor = $this->staffActor($school);

        $this->expectException(GuardianRelationshipNotEligibleException::class);

        $this->service()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);
    }

    #[Test]
    public function guardian_consent_is_refused_when_the_relationship_belongs_to_a_different_student(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $otherStudent = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($otherStudent, $guardian, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($school);

        $this->expectException(GuardianRelationshipNotEligibleException::class);

        $this->service()->recordGuardianConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $relationship, $actor);
    }

    #[Test]
    public function guardian_consent_is_refused_when_the_relationship_belongs_to_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['date_of_birth' => '2012-01-01']);
        $guardianB = $this->createGuardian($schoolB);
        $relationshipInB = $this->createStudentGuardianRelationship($this->createStudent($schoolB), $guardianB, ['is_legal_guardian' => true]);
        $actor = $this->staffActor($schoolA);

        $this->expectException(GuardianRelationshipNotEligibleException::class);

        $this->service()->recordGuardianConsent($schoolA, $studentA, ProcessingAuthorizationPurpose::AcademicRecords, $relationshipInB, $actor);
    }

    #[Test]
    public function adult_student_consent_is_recorded_with_no_relationship(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);

        $grant = $this->service()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->assertSame(ProcessingAuthorizationBasisType::AdultStudentConsent, $grant->basis_type);
        $this->assertNull($grant->student_guardian_relationship_id);
    }

    #[Test]
    public function statutory_school_purpose_is_recorded_with_no_provider(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $actor = $this->staffActor($school);

        $grant = $this->service()->recordStatutorySchoolPurpose($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->assertSame(ProcessingAuthorizationBasisType::StatutorySchoolPurpose, $grant->basis_type);
        $this->assertNull($grant->student_guardian_relationship_id);
    }

    #[Test]
    public function withdraw_terminates_the_grant_and_is_audited(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = $this->service()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $terminal = $this->service()->withdraw($school, $grant, $actor, 'requested by Student');

        $this->assertSame(ProcessingAuthorizationStatus::Withdrawn, $terminal->status);
        $this->assertSame($grant->id, $terminal->terminates_authorization_id);
        $this->assertSame(1, $this->auditEventCount($school, 'students.processing_authorization.withdrawn'));
    }

    #[Test]
    public function revoke_terminates_the_grant_with_a_distinct_status_from_withdrawal(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = $this->service()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $terminal = $this->service()->revoke($school, $grant, $actor, 'incorrectly recorded');

        $this->assertSame(ProcessingAuthorizationStatus::Revoked, $terminal->status);
    }

    #[Test]
    public function a_grant_cannot_be_terminated_twice(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = $this->service()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $this->service()->withdraw($school, $grant, $actor);

        $this->expectException(ProcessingAuthorizationAlreadyTerminatedException::class);
        $this->service()->revoke($school, $grant, $actor);
    }

    #[Test]
    public function supersede_atomically_terminates_the_old_grant_and_records_a_new_one(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $old = $this->service()->recordStatutorySchoolPurpose($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $result = $this->service()->supersede($school, $old, ProcessingAuthorizationBasisType::AdultStudentConsent, $actor);

        $this->assertSame(ProcessingAuthorizationStatus::Superseded, $result['terminal']->status);
        $this->assertSame($old->id, $result['terminal']->terminates_authorization_id);
        $this->assertSame(ProcessingAuthorizationStatus::Recorded, $result['new']->status);
        $this->assertSame(ProcessingAuthorizationBasisType::AdultStudentConsent, $result['new']->basis_type);
    }

    #[Test]
    public function note_is_never_copied_into_audit_metadata(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);

        $distinctiveNote = 'DISTINCTIVE_NOTE_MARKER_'.uniqid();
        $this->service()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor, $distinctiveNote);

        $event = $this->auditEvent($school, 'students.processing_authorization.recorded');

        $this->assertStringNotContainsString($distinctiveNote, json_encode($event->metadata));
    }

    #[Test]
    public function date_of_birth_is_never_copied_into_audit_metadata(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '1999-03-03']);
        $actor = $this->staffActor($school);

        $this->service()->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        $event = $this->auditEvent($school, 'students.processing_authorization.recorded');

        $this->assertStringNotContainsString('1999-03-03', json_encode($event->metadata));
    }
}
