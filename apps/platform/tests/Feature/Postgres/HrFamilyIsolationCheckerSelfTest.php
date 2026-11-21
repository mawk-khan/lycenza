<?php

namespace Tests\Feature\Postgres;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsHrFamilyIsolation;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-26 (C38-N1, brief Part H): the isolation checker can fail. Each probe table below is built broken in
 * exactly one way, with real rows of two Schools, and the checker that `HrFamilyTenantIsolationTest` runs over the
 * 57 HR-family tables must report that break, naming it. A correct control table must report nothing.
 *
 * The probe tables are scratch tables written on the admin connection, so this test commits (no wrapping
 * transaction) and drops them in tearDown, like `UserReferenceProbeTest`. The broken policies are hand-written on
 * purpose: they are the regressions being detected; production tables only ever get `TenantRls::enable()` (rule 18).
 */
class HrFamilyIsolationCheckerSelfTest extends TestCase
{
    use AssertsHrFamilyIsolation;

    protected $connectionsToTransact = [];

    private const PROBES = [
        'wo26_probe_control', 'wo26_probe_not_forced', 'wo26_probe_disabled', 'wo26_probe_open_read', 'wo26_probe_open_write',
        'wo26_probe_no_school_predicate', 'wo26_probe_empty', 'wo26_probe_guarded', 'wo26_probe_open_without_context', 'wo26_probe_no_insert',
    ];

    private string $a;

    private string $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = (string) Str::uuid();
        $this->b = (string) Str::uuid();
        $admin = DB::connection('pgsql_admin');
        $expr = "school_id = NULLIF(current_setting('app.current_school_id', true), '')::uuid";
        foreach (self::PROBES as $table) {
            $admin->statement("DROP TABLE IF EXISTS {$table}");
            $admin->statement("CREATE TABLE {$table} (id uuid PRIMARY KEY, school_id uuid NOT NULL, label text NOT NULL)");
            $admin->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO school_os_app");
        }
        $policy = fn (string $t, string $using, string $check) => $admin->statement("CREATE POLICY {$t}_tenant ON {$t} USING ({$using}) WITH CHECK ({$check})");
        $secured = ['wo26_probe_control', 'wo26_probe_open_read', 'wo26_probe_open_write', 'wo26_probe_no_school_predicate', 'wo26_probe_empty', 'wo26_probe_guarded', 'wo26_probe_open_without_context', 'wo26_probe_no_insert'];
        foreach ([...$secured, 'wo26_probe_not_forced'] as $t) {
            $admin->statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        }
        foreach ($secured as $t) {
            $admin->statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
        }
        $policy('wo26_probe_control', $expr, $expr);
        $policy('wo26_probe_not_forced', $expr, $expr);
        $policy('wo26_probe_empty', $expr, $expr);
        $policy('wo26_probe_open_read', 'true', $expr);
        $policy('wo26_probe_open_write', $expr, 'true');
        $policy('wo26_probe_no_insert', $expr, $expr);
        $admin->statement('REVOKE INSERT ON wo26_probe_no_insert FROM school_os_app');
        // A guard that refuses every insert in every School, whatever RLS would say (WITH CHECK left open to show it).
        $policy('wo26_probe_guarded', $expr, 'true');
        $admin->statement("CREATE OR REPLACE FUNCTION wo26_probe_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'wo26 probe: always refused' USING ERRCODE = 'check_violation'; END \$\$");
        // Isolated between Schools, but fails OPEN when no School is set (rule 28 asks it to fail closed).
        $policy('wo26_probe_open_without_context', "school_id = coalesce(NULLIF(current_setting('app.current_school_id', true), '')::uuid, school_id)", $expr);
        $policy('wo26_probe_no_school_predicate', "current_setting('app.current_school_id', true) IS NOT NULL", "current_setting('app.current_school_id', true) IS NOT NULL");
        foreach (array_diff(self::PROBES, ['wo26_probe_empty']) as $t) {
            foreach ([$this->a => 'Alpha', $this->b => 'Beta'] as $school => $label) {
                $admin->insert("INSERT INTO {$t} (id, school_id, label) VALUES (?, ?, ?)", [(string) Str::uuid(), $school, $label]);
            }
        }
        // After the seed, so only the probe's own inserts meet it.
        $admin->statement('CREATE TRIGGER wo26_probe_guard BEFORE INSERT ON wo26_probe_guarded FOR EACH ROW EXECUTE FUNCTION wo26_probe_guard()');
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql')->statement('RESET app.current_school_id');
        foreach (self::PROBES as $table) {
            DB::connection('pgsql_admin')->statement("DROP TABLE IF EXISTS {$table}");
        }
        DB::connection('pgsql_admin')->statement('DROP FUNCTION IF EXISTS wo26_probe_guard()');
        parent::tearDown();
    }

    /** @return list<string> */
    private function check(string $table): array
    {
        return [...$this->policyViolations($table), ...$this->isolationViolations($table, $this->a, $this->b)];
    }

    private function assertReported(string $table, string $expected): void
    {
        $violations = $this->check($table);
        $this->assertNotSame([], array_filter($violations, fn (string $v) => str_contains($v, $expected)), "{$table}: the checker must report \"{$expected}\"; it reported:\n".implode("\n", $violations));
    }

    #[Test]
    public function a_correct_tenant_table_reports_nothing(): void
    {
        $this->assertSame([], $this->roleViolations());
        $control = $this->isolationViolations('wo26_probe_control', $this->a, $this->b);
        $this->assertSame([], $control, implode("\n", $control));
        // The control's policy is hand-written to the same expression, so the catalogue deparse must equal the pin.
        $this->assertSame([], $this->policyViolations('wo26_probe_control'));
    }

    #[Test]
    public function every_planted_break_is_reported_by_name(): void
    {
        $this->assertReported('wo26_probe_not_forced', 'expected FORCE ROW LEVEL SECURITY');
        $this->assertReported('wo26_probe_disabled', 'expected RLS enabled');
        $this->assertReported('wo26_probe_disabled', 'School B context: SELECT School A\'s rows: expected 0');
        $this->assertReported('wo26_probe_disabled', 'no context (reset): SELECT: expected 0');
        $this->assertReported('wo26_probe_open_read', 'policy USING: expected the tenant expression');
        $this->assertReported('wo26_probe_open_read', 'School B context: SELECT School A\'s rows: expected 0');
        $this->assertReported('wo26_probe_open_read', 'School B context: DELETE School A\'s rows: expected 0 rows');
        $this->assertReported('wo26_probe_open_write', 'policy WITH CHECK: expected the tenant expression');
        $this->assertReported('wo26_probe_open_write', 'INSERT a School A row: expected refusal; actual accepted');
        // A move stays refused even here: an UPDATE with a WHERE clause also checks the new row against the policy's
        // USING (PostgreSQL), so the open WITH CHECK shows on INSERT and in the catalogue, not on the move.
        $this->assertReported('wo26_probe_no_school_predicate', 'School B context: SELECT School A\'s rows: expected 0');
        $this->assertReported('wo26_probe_empty', 'expected at least one fixture row; actual 0');
        // The differential control: a guard that refuses in every School is never mistaken for the School boundary.
        $this->assertReported('wo26_probe_guarded', 'refused for a reason independent of the School');
        // Fails open only without context: both no-context legs are reported, and the foreign read is not.
        $this->assertReported('wo26_probe_open_without_context', 'no context (reset): SELECT: expected 0');
        $this->assertReported('wo26_probe_open_without_context', 'no context (empty): SELECT: expected 0');
        $this->assertSame([], array_filter($this->isolationViolations('wo26_probe_open_without_context', $this->a, $this->b), fn (string $v) => str_contains($v, 'School B context: SELECT')));
    }

    #[Test]
    public function a_missing_privilege_is_recorded_as_a_privilege_refusal_never_as_rls(): void
    {
        $violations = $this->isolationViolations('wo26_probe_no_insert', $this->a, $this->b);

        $this->assertSame([], $violations, implode("\n", $violations));
        $this->assertStringStartsWith('no INSERT privilege', $this->isolationEvidence['wo26_probe_no_insert insert']);
        $this->assertStringStartsWith('RLS', $this->isolationEvidence['wo26_probe_no_insert move']);
        // The scratch tables are granted DELETE (unlike every family table, R1-01), so this exercises the other DELETE
        // branch: from School B it touches none of School A's rows.
        $this->assertSame('RLS: 0 rows from the other School', $this->isolationEvidence['wo26_probe_no_insert delete']);
    }

    #[Test]
    public function the_checker_refuses_to_vouch_for_a_privileged_connection(): void
    {
        $this->isolationConnection = 'pgsql_admin';
        try {
            $this->assertStringContainsString('expected the runtime role school_os_app', implode("\n", $this->roleViolations()));
            // As the owner with BYPASSRLS, even the correct table leaks: exactly why every probe must run as the runtime role.
            $this->assertNotSame([], $this->isolationViolations('wo26_probe_control', $this->a, $this->b));
        } finally {
            $this->isolationConnection = 'pgsql';
            DB::connection('pgsql_admin')->statement('RESET app.current_school_id');
        }
    }
}
