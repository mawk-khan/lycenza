<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsRuntimeDeleteRevoked;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A (section 24/30, mandatory): proves tenant isolation for
 * `students` and `guardians` at the raw-SQL level against real
 * PostgreSQL, under the unprivileged `school_os_app` runtime role --
 * independent of Eloquent's SchoolScope, exactly like
 * AcademicStructureRlsIsolationTest. Controller-level filtering is
 * never treated as sufficient on its own.
 */
class StudentGuardianRlsIsolationTest extends TestCase
{
    use AssertsRuntimeDeleteRevoked, CreatesTenancyFixtures;

    private const TABLES = ['students', 'guardians'];

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function every_student_guardian_table_has_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "{$table} must exist");
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_rows_on_students_and_guardians(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => 'S-0001']);
        $this->createGuardian($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        foreach (self::TABLES as $table) {
            $count = DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
            $this->assertSame(0, (int) $count, "{$table} must be invisible with no TenantContext");
        }
    }

    #[Test]
    public function school_a_cannot_read_school_bs_rows_on_students_or_guardians(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);

        $this->setSchool($schoolA->id);

        $ids = [
            'students' => $studentB->id,
            'guardians' => $guardianB->id,
        ];

        foreach ($ids as $table => $id) {
            $rows = DB::connection('pgsql')->select("select id from {$table} where id = ?", [$id]);
            $this->assertCount(0, $rows, "School A must not see School B's row in {$table}");
        }
    }

    #[Test]
    public function school_a_cannot_discover_school_bs_student_or_guardian_by_uuid_lookup(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);

        $this->setSchool($schoolA->id);

        $this->assertCount(0, DB::connection('pgsql')->select('select 1 from students where id = ?', [$studentB->id]));
        $this->assertCount(0, DB::connection('pgsql')->select('select 1 from guardians where id = ?', [$guardianB->id]));
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows_on_students_and_guardians(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $studentB = $this->createStudent($schoolB, ['student_number' => 'S-0001']);
        $guardianB = $this->createGuardian($schoolB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update("update students set status = 'inactive' where id = ?", [$studentB->id]));
        $this->assertSame(0, DB::connection('pgsql')->update("update guardians set status = 'inactive' where id = ?", [$guardianB->id]));
        $this->assertRuntimeDeleteRevoked('delete from students where id = ?', [$studentB->id]);
        $this->assertRuntimeDeleteRevoked('delete from guardians where id = ?', [$guardianB->id]);
    }
}
