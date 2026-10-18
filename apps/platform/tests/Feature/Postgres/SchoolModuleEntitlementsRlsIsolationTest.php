<?php

namespace Tests\Feature\Postgres;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.5 (ADR 0076 §12.3, §31; CLAUDE.md rules 18, 28): school_module_entitlements
 * is tenant-owned under FORCE RLS, holds only optional module keys and closed
 * reason codes, one row per (School, module), and the runtime role can never
 * DELETE a row.
 */
class SchoolModuleEntitlementsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /** Runs one statement in a savepoint and reports whether the database refused it. */
    private function refused(callable $statement): bool
    {
        try {
            DB::connection('pgsql')->transaction($statement);
        } catch (QueryException) {
            return true;
        }

        return false;
    }

    private function row(string $schoolId, string $module, array $overrides = []): array
    {
        return [
            'id' => (string) Str::uuid7(), 'school_id' => $schoolId, 'module_key' => $module, 'enabled' => true,
            'reason_code' => 'platform_policy', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ...$overrides,
        ];
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
            ['school_module_entitlements', 'public'],
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows_and_school_a_sees_only_its_own(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from school_module_entitlements')->c);

        $this->setSchool($schoolA->id);
        $schools = DB::connection('pgsql')->select('select distinct school_id from school_module_entitlements');
        $this->assertSame([$schoolA->id], array_map(fn ($r) => $r->school_id, $schools));
        $this->assertSame(0, DB::connection('pgsql')->update('update school_module_entitlements set enabled = false where school_id = ?', [$schoolB->id]));
    }

    #[Test]
    public function school_a_cannot_insert_a_row_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);
        $this->assertTrue($this->refused(fn () => DB::connection('pgsql')->table('school_module_entitlements')->insert($this->row($schoolB->id, 'library'))));
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_row(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);

        $this->assertTrue($this->refused(fn () => DB::connection('pgsql')->delete('delete from school_module_entitlements where school_id = ?', [$school->id])));
        $this->assertSame(count(ModuleCatalogue::optionalKeys()), (int) DB::connection('pgsql')->selectOne('select count(*) as c from school_module_entitlements where school_id = ?', [$school->id])->c);
    }

    #[Test]
    public function unknown_and_core_keys_and_unknown_reasons_are_refused_and_rows_are_unique(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);
        $table = fn () => DB::connection('pgsql')->table('school_module_entitlements');

        $this->assertTrue($this->refused(fn () => $table()->insert($this->row($school->id, 'billing'))), 'unknown module key');
        $this->assertTrue($this->refused(fn () => $table()->insert($this->row($school->id, 'student_records'))), 'core module key');
        $this->assertTrue($this->refused(fn () => $table()->where('school_id', $school->id)->where('module_key', 'library')->update(['reason_code' => 'paid_plan'])), 'unknown reason');
        $this->assertTrue($this->refused(fn () => $table()->insert($this->row($school->id, 'library'))), 'duplicate (School, module)');
    }
}
