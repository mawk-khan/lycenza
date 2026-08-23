<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.2 (mandatory): `student_guardian_relationships` proven at the
 * raw-SQL level against real PostgreSQL, under the unprivileged
 * `school_os_app` runtime role, independent of Eloquent -- mirrors
 * StudentGuardianRlsIsolationTest's and AcademicStructureCrossRelationTest's
 * patterns. Covers both RLS isolation (this table's own tenant
 * boundary) and the composite-FK cross-School integrity guarantee that
 * makes "School A Student + School B Guardian" structurally impossible,
 * not just application-validated.
 */
class StudentGuardianRelationshipIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertRelationship(string $schoolId, string $studentId, string $guardianId, ?string $connection = 'pgsql'): void
    {
        DB::connection($connection)->insert(
            'insert into student_guardian_relationships '.
            '(id, school_id, student_id, guardian_id, relationship_type, created_at, updated_at) '.
            "values (?, ?, ?, ?, 'other', now(), now())",
            [(string) Str::orderedUuid(), $schoolId, $studentId, $guardianId],
        );
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['student_guardian_relationships', 'public'],
        );

        $this->assertNotNull($row, 'student_guardian_relationships must exist');
        $this->assertTrue($row->relrowsecurity, 'student_guardian_relationships must have RLS enabled');
        $this->assertTrue($row->relforcerowsecurity, 'student_guardian_relationships must FORCE RLS');
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from student_guardian_relationships')->c;
        $this->assertSame(0, (int) $count, 'student_guardian_relationships must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_relationship_row(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);
        $relationshipB = $this->createStudentGuardianRelationship($studentB, $guardianB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from student_guardian_relationships where id = ?', [$relationshipB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's relationship row");
    }

    #[Test]
    public function school_a_cannot_discover_school_bs_relationship_by_uuid(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);
        $relationshipB = $this->createStudentGuardianRelationship($studentB, $guardianB);

        $this->setSchool($schoolA->id);

        $this->assertCount(0, DB::connection('pgsql')->select(
            'select 1 from student_guardian_relationships where id = ?',
            [$relationshipB->id],
        ));
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);
        $relationshipB = $this->createStudentGuardianRelationship($studentB, $guardianB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            'update student_guardian_relationships set is_primary = true where id = ?',
            [$relationshipB->id],
        ));
        $this->assertSame(0, DB::connection('pgsql')->delete(
            'delete from student_guardian_relationships where id = ?',
            [$relationshipB->id],
        ));
    }

    #[Test]
    public function school_a_cannot_create_a_relationship_assigned_to_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT) so the
        // expected RLS WITH CHECK failure rolls back cleanly instead of
        // leaving the `pgsql` connection in Postgres's "current
        // transaction is aborted" state for tearDown()'s RESET
        // statement -- see WebhookRlsIsolationTest's identical pattern.
        DB::connection('pgsql')->transaction(function () use ($schoolB, $studentB, $guardianB): void {
            $this->insertRelationship($schoolB->id, $studentB->id, $guardianB->id);
        });
    }

    #[Test]
    public function a_relationship_cannot_reference_another_schools_student(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianA = $this->createGuardian($schoolA);

        $this->expectException(QueryException::class);

        // Via pgsql_admin (bypasses RLS's own WITH CHECK, isolating that
        // the failure is purely the composite FK) -- mirrors
        // AcademicStructureCrossRelationTest's pattern.
        $this->insertRelationship($schoolA->id, $studentB->id, $guardianA->id, 'pgsql_admin');
    }

    #[Test]
    public function a_relationship_cannot_reference_another_schools_guardian(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);

        $this->expectException(QueryException::class);

        $this->insertRelationship($schoolA->id, $studentA->id, $guardianB->id, 'pgsql_admin');
    }
}
