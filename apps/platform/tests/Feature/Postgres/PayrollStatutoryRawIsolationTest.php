<?php

namespace Tests\Feature\Postgres;

use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6H (rule 28, ADR 0036 correction addendum's privacy
 * requirements) -- raw-SQL, no-application-layer proof that School A
 * can never read School B's statutory identifiers, mirroring
 * `RawIsolationTest`'s exact discipline (real `pgsql`/`school_os_app`
 * connection, `TenantRls::SESSION_VAR` session context, never
 * Eloquent's SchoolScope). `employee_statutory_identifiers` is the
 * single highest-risk statutory table (real, encrypted government
 * identifiers) -- this is the targeted proof for it, not a repeat of
 * the generic RLS mechanism already proven for every other tenant
 * table across the codebase.
 */
class PayrollStatutoryRawIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function raw_select_with_school_a_context_never_sees_school_bs_statutory_identifiers(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $context = app(TenantContext::class);

        $employmentRecordA = $context->withSchool($schoolA, fn () => $this->createEmploymentRecord($this->createEmployee($schoolA)));
        $employmentRecordB = $context->withSchool($schoolB, fn () => $this->createEmploymentRecord($this->createEmployee($schoolB)));

        $identifierA = $context->withSchool($schoolA, fn () => EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $schoolA->id,
            'employment_record_id' => $employmentRecordA->id,
            'identifier_type' => 'uan',
            'encrypted_value' => '100000000001',
            'lookup_hash' => str_repeat('a', 64),
        ]));
        $context->withSchool($schoolB, fn () => EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $schoolB->id,
            'employment_record_id' => $employmentRecordB->id,
            'identifier_type' => 'uan',
            'encrypted_value' => '200000000002',
            'lookup_hash' => str_repeat('b', 64),
        ]));

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);

        $rows = DB::connection('pgsql')->select('select id, school_id from employee_statutory_identifiers');

        $this->assertCount(1, $rows, 'School A session must see exactly its own statutory identifier row, never School B\'s.');
        $this->assertSame($identifierA->id, $rows[0]->id);
        $this->assertSame($schoolA->id, $rows[0]->school_id);
    }

    #[Test]
    public function raw_select_with_no_school_context_returns_zero_statutory_identifier_rows(): void
    {
        $schoolA = $this->createSchool();
        $context = app(TenantContext::class);
        $employmentRecordA = $context->withSchool($schoolA, fn () => $this->createEmploymentRecord($this->createEmployee($schoolA)));
        $context->withSchool($schoolA, fn () => EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $schoolA->id,
            'employment_record_id' => $employmentRecordA->id,
            'identifier_type' => 'pan',
            'encrypted_value' => 'ABCDE1234F',
            'lookup_hash' => str_repeat('c', 64),
        ]));

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from employee_statutory_identifiers')->c;

        $this->assertSame(0, (int) $count, 'No active School context must fail closed to zero rows, never expose every School\'s identifiers.');
    }
}
