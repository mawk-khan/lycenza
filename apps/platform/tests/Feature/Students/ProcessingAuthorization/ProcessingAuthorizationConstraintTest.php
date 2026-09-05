<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P2 §14/§21 -- proves the database CHECK/composite-FK
 * invariants directly via raw SQL, not merely via application-layer
 * behavior (which could be bypassed by a direct write).
 */
class ProcessingAuthorizationConstraintTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    #[Test]
    public function a_terminal_event_cannot_reference_a_grant_belonging_to_a_different_purpose(): void
    {
        // There is currently only one closed purpose value, so this
        // proves the CHECK constraint rejects it outright rather than
        // exercising a cross-purpose scenario that cannot exist yet.
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = app(StudentProcessingAuthorizationService::class)->recordStatutorySchoolPurpose($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into student_processing_authorizations '.
            '(id, school_id, student_id, purpose, basis_type, status, recorded_at, recorded_by_user_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, now(), ?, now(), now())',
            [(string) Str::orderedUuid(), $school->id, $student->id, 'not_a_real_purpose', 'statutory_school_purpose', 'recorded', $actor->id],
        );
    }

    #[Test]
    public function a_terminal_event_cannot_reference_a_grant_belonging_to_a_different_student(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $studentB = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grantA = app(StudentProcessingAuthorizationService::class)->recordStatutorySchoolPurpose($school, $studentA, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        // Attempt to insert a terminal event for studentB that claims
        // to terminate studentA's grant -- the composite FK on
        // (terminates_authorization_id, school_id, student_id, purpose)
        // must reject this: no row in the parent table has grantA's id
        // paired with studentB's id.
        DB::connection('pgsql')->insert(
            'insert into student_processing_authorizations '.
            '(id, school_id, student_id, purpose, basis_type, status, terminates_authorization_id, recorded_at, recorded_by_user_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, ?, now(), ?, now(), now())',
            [(string) Str::orderedUuid(), $school->id, $studentB->id, 'academic_records', 'statutory_school_purpose', 'withdrawn', $grantA->id, $actor->id],
        );
    }

    #[Test]
    public function a_recorded_row_cannot_carry_a_terminates_authorization_id(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $existing = app(StudentProcessingAuthorizationService::class)->recordStatutorySchoolPurpose($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into student_processing_authorizations '.
            '(id, school_id, student_id, purpose, basis_type, status, terminates_authorization_id, recorded_at, recorded_by_user_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, ?, now(), ?, now(), now())',
            [(string) Str::orderedUuid(), $school->id, $student->id, 'academic_records', 'statutory_school_purpose', 'recorded', $existing->id, $actor->id],
        );
    }

    #[Test]
    public function a_terminal_row_must_carry_a_terminates_authorization_id(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into student_processing_authorizations '.
            '(id, school_id, student_id, purpose, basis_type, status, recorded_at, recorded_by_user_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, now(), ?, now(), now())',
            [(string) Str::orderedUuid(), $school->id, $student->id, 'academic_records', 'statutory_school_purpose', 'withdrawn', $actor->id],
        );
    }

    #[Test]
    public function guardian_consent_requires_a_relationship_id_at_the_database_level(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2012-01-01']);
        $actor = $this->staffActor($school);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into student_processing_authorizations '.
            '(id, school_id, student_id, purpose, basis_type, status, recorded_at, recorded_by_user_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, now(), ?, now(), now())',
            [(string) Str::orderedUuid(), $school->id, $student->id, 'academic_records', 'guardian_consent', 'recorded', $actor->id],
        );
    }

    #[Test]
    public function a_second_terminal_event_for_the_same_grant_is_rejected_at_the_database_level(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $service = app(StudentProcessingAuthorizationService::class);
        $grant = $service->recordStatutorySchoolPurpose($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);
        $service->withdraw($school, $grant, $actor);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into student_processing_authorizations '.
            '(id, school_id, student_id, purpose, basis_type, status, terminates_authorization_id, recorded_at, recorded_by_user_id, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, ?, now(), ?, now(), now())',
            [(string) Str::orderedUuid(), $school->id, $student->id, 'academic_records', 'statutory_school_purpose', 'revoked', $grant->id, $actor->id],
        );
    }
}
