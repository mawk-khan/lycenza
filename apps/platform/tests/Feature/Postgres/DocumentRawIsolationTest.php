<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsRuntimeDeleteRevoked;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.1 (CLAUDE.md rule 28, mandatory): proves tenant isolation
 * for `documents` at the raw-SQL level against real PostgreSQL, under
 * the unprivileged `school_os_app` runtime role -- independent of
 * Eloquent's SchoolScope, exactly like StudentGuardianRlsIsolationTest/
 * HrRawIsolationTest. Controller-level filtering is never treated as
 * sufficient on its own. Uses the employee-owner arm throughout --
 * RLS isolation is enforced on `school_id` alone and is identical
 * regardless of which owner column (employee_id/student_id/
 * guardian_id) is set.
 */
class DocumentRawIsolationTest extends TestCase
{
    use AssertsRuntimeDeleteRevoked, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function documents_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['documents', 'public'],
        );

        $this->assertNotNull($row, 'documents must exist');
        $this->assertTrue($row->relrowsecurity, 'documents must have RLS enabled');
        $this->assertTrue($row->relforcerowsecurity, 'documents must FORCE RLS');
    }

    #[Test]
    public function no_school_context_sees_zero_documents(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createDocumentForEmployee($employee);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from documents')->c;
        $this->assertSame(0, (int) $count, 'documents must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_document(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $employeeB = $this->createEmployee($schoolB);
        $documentB = $this->createDocumentForEmployee($employeeB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from documents where id = ?', [$documentB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's document");
    }

    #[Test]
    public function cross_school_writes_on_documents_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $employeeB = $this->createEmployee($schoolB);
        $documentB = $this->createDocumentForEmployee($employeeB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update documents set status = 'archived' where id = ?",
            [$documentB->id],
        ));
        $this->assertRuntimeDeleteRevoked(
            'delete from documents where id = ?',
            [$documentB->id],
        );
    }

    #[Test]
    public function raw_insert_of_a_document_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into documents '.
            '(id, school_id, employee_id, classification_tier, storage_disk, storage_path, original_filename, mime_type, size_bytes, uploaded_at, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, ?, 'internal', 'local', 'documents/x.pdf', 'x.pdf', 'application/pdf', 100, now(), 'active', now(), now())",
            [$schoolB->id, $employeeB->id],
        );
    }
}
