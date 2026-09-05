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
 * Phase 0H.4D-P2 §45/§46 -- real PostgreSQL, raw-SQL proofs, mirroring
 * Tests\Feature\Postgres\RawIsolationTest's exact pattern.
 */
class ProcessingAuthorizationRlsAndAppendOnlyTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'student_processing_authorizations' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function school_a_cannot_read_school_bs_authorization_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['date_of_birth' => '2000-01-01']);
        $actorB = $this->staffActor($schoolB);
        app(StudentProcessingAuthorizationService::class)->recordAdultStudentConsent($schoolB, $studentB, ProcessingAuthorizationPurpose::AcademicRecords, $actorB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id from student_processing_authorizations');

        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_an_authorization_for_school_bs_student(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['date_of_birth' => '2000-01-01']);
        $actorB = $this->staffActor($schoolB);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $studentB, $actorB): void {
            DB::connection('pgsql')->insert(
                'insert into student_processing_authorizations '.
                '(id, school_id, student_id, purpose, basis_type, status, recorded_at, recorded_by_user_id, created_at, updated_at) '.
                'values (?, ?, ?, ?, ?, ?, now(), ?, now(), now())',
                [(string) Str::orderedUuid(), $schoolB->id, $studentB->id, 'academic_records', 'statutory_school_purpose', 'recorded', $actorB->id],
            );
        });
    }

    #[Test]
    public function missing_tenant_context_returns_zero_rows_not_all_rows(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        app(StudentProcessingAuthorizationService::class)->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from student_processing_authorizations')->c;

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function the_runtime_role_cannot_update_a_row(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = app(StudentProcessingAuthorizationService::class)->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update('update student_processing_authorizations set note = ? where id = ?', ['tampered', $grant->id]);
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_row(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $grant = app(StudentProcessingAuthorizationService::class)->recordAdultStudentConsent($school, $student, ProcessingAuthorizationPurpose::AcademicRecords, $actor);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->delete('delete from student_processing_authorizations where id = ?', [$grant->id]);
    }
}
