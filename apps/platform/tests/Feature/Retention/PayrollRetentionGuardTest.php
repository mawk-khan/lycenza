<?php

namespace Tests\Feature\Retention;

use App\Domain\Payroll\Application\Retention\PayrollEvidenceRetentionService;
use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3F (E21-D9): posted payroll evidence leaves ONLY through the two
 * narrow retention functions, which re-prove every unit in the database;
 * the runtime role gains no privilege, Finance keeps sole ownership of
 * journal entries, and only the payroll retention command reaches the
 * mechanism.
 */
class PayrollRetentionGuardTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesPayrollRetentionFixtures;

    /** A School with one posted 2015 run for one Employee (statutory included) and its result id. */
    private function posted(?string $separatedOn = '2015-12-31'): array
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $this->statutorySetup($school, $setup);
        $employee = $this->paidEmployee($school, $setup);
        $run = $this->postedRun($school, '2015-08-01', '2015-08-31', statutory: true);
        if ($separatedOn !== null) {
            $this->separate($employee, $separatedOn);
        }
        $result = (string) $this->inSchool($school, fn () => DB::table('payroll_run_results')->where('employee_id', $employee->id)->value('id'));

        return compact('school', 'employee', 'run', 'result');
    }

    /** The database's refusal message for $statement, run as the runtime role in a savepoint ('' when it succeeds). */
    private function refusal(School $school, callable $statement): string
    {
        try {
            $this->inSchool($school, fn () => DB::transaction($statement));

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    /** E21-RH.5: $statement as the retention identity, on its own connection: '' or the refusal. */
    private function asRetention(School $school, callable $statement): string
    {
        return DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $this->refusal($school, $statement));
    }

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> app-relative files whose code contains $needle */
    private function filesContaining(string $needle): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path()).' -name "*.php"', $files);
        sort($files);

        return array_values(array_map(fn ($f) => substr($f, strlen(app_path()) + 1), array_filter($files, fn ($f) => str_contains($this->code($f), $needle))));
    }

    #[Test]
    public function the_runtime_role_cannot_delete_or_alter_posted_payroll_evidence_directly(): void
    {
        $w = $this->posted();
        $school = $w['school'];

        $this->assertStringContainsString('results are frozen', $this->refusal($school, fn () => DB::table('payroll_run_results')->where('id', $w['result'])->delete()));
        $this->assertStringContainsString('lines are frozen', $this->refusal($school, fn () => DB::table('payroll_run_result_lines')->where('payroll_run_result_id', $w['result'])->delete()));
        $this->assertStringContainsString('statutory results are frozen', $this->refusal($school, fn () => DB::table('payroll_statutory_calculation_results')->where('payroll_run_result_id', $w['result'])->delete()));
        foreach (['payroll_adjustments', 'payroll_run_postings', 'payroll_statutory_run_postings'] as $table) {
            $this->assertStringContainsString('permission denied', $this->refusal($school, fn () => DB::table($table)->where('school_id', $school->id)->delete()), "{$table}: no runtime DELETE");
        }
        $this->assertStringContainsString('violates foreign key', $this->refusal($school, fn () => DB::table('payroll_runs')->where('id', $w['run']->id)->delete()), 'a posted run is held by its postings');
        $this->assertStringContainsString('set only by payroll retention', $this->refusal($school, fn () => DB::table('payroll_runs')->where('id', $w['run']->id)->update(['results_expired_at' => now()])));
        // Even the flag alone does not help the runtime role: the owner's privileges are required too.
        $this->assertStringContainsString('results are frozen', $this->refusal($school, function () use ($w) {
            DB::statement("SELECT set_config('app.payroll_retention', 'on', true)");
            DB::table('payroll_run_results')->where('id', $w['result'])->delete();
        }));
        $this->assertSame(1, $this->rowsWhere($school, 'payroll_run_results', 'id', $w['result']));
    }

    #[Test]
    public function the_functions_re_prove_tenant_floor_separation_and_unit_shape_themselves(): void
    {
        $w = $this->posted();
        $other = $this->createSchool();
        $expiry = app(RetentionExpiry::class);
        $old = '2018-01-01';

        // E21-RH.5: the runtime role cannot run either function at all.
        $this->assertStringContainsString('permission denied for function retention_expire_payroll_employee_evidence', $this->refusal($w['school'], fn () => $expiry->payrollEmployeeEvidence($w['school'], $w['employee']->id, $old, true)));
        $this->assertStringContainsString('permission denied for function retention_expire_payroll_run', $this->refusal($w['school'], fn () => $expiry->payrollRun($w['school'], $w['run']->id, now()->subYears(9), true)));
        // As the retention identity, the database still re-proves every rule:

        // Wrong School context: the School must be the current tenant.
        $this->assertStringContainsString('retention_tenant', $this->asRetention($other, fn () => $expiry->payrollEmployeeEvidence($w['school'], $w['employee']->id, $old, true)));
        // Another School's Employee under this School's context is not proven separated here.
        $this->assertStringContainsString('retention_payroll_employee', $this->asRetention($other, fn () => $expiry->payrollEmployeeEvidence($other, $w['employee']->id, $old, true)));
        // A cutoff younger than 8 calendar years is refused, whatever the caller computed.
        $this->assertStringContainsString('retention_floor', $this->asRetention($w['school'], fn () => $expiry->payrollEmployeeEvidence($w['school'], $w['employee']->id, now()->subYears(7)->toDateString(), true)));
        $this->assertStringContainsString('retention_floor', $this->asRetention($w['school'], fn () => $expiry->payrollRun($w['school'], $w['run']->id, now()->subYears(7), true)));
        // A run still holding evidence is never released.
        $this->assertStringContainsString('retention_payroll_dependency', $this->asRetention($w['school'], fn () => $expiry->payrollRun($w['school'], $w['run']->id, now()->subYears(9), true)));
        // An active (or rehired) Employee is refused.
        $active = $this->posted(null);
        $this->assertStringContainsString('retention_payroll_employee', $this->asRetention($active['school'], fn () => $expiry->payrollEmployeeEvidence($active['school'], $active['employee']->id, $old, true)));
        // Separated, but only 2020: younger than the given cutoff.
        $this->separate($active['employee'], '2020-06-30');
        $this->assertStringContainsString('retention_payroll_employee', $this->asRetention($active['school'], fn () => $expiry->payrollEmployeeEvidence($active['school'], $active['employee']->id, $old, true)));

        // The proper unit is accepted (a dry run counts the result, the LWF charge).
        $this->assertSame(2, DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => $expiry->payrollEmployeeEvidence($w['school'], $w['employee']->id, $old, true)))));
    }

    #[Test]
    public function the_payroll_units_run_whole_as_the_retention_identity_and_obey_database_holds(): void
    {
        $a = $this->posted();
        $b = $this->posted();
        $payroll = app(PayrollEvidenceRetentionService::class);
        $expiry = app(RetentionExpiry::class);
        $holds = app(RetentionHolds::class);
        $cutoff = CarbonImmutable::now('UTC')->subYearsNoOverflow(8)->startOfDay();
        $evidence = fn (array $w, bool $dryRun): string => $this->asRetention($w['school'], fn () => $expiry->payrollEmployeeEvidence($w['school'], $w['employee']->id, $cutoff->toDateString(), $dryRun));

        // A School hold that exists only in the database: PostgreSQL refuses, dry runs included.
        $holds->place($a['school']->id, 'litigation', 'PAY-HOLD-1');
        $this->assertStringContainsString('retention_hold', $evidence($a, true));
        $this->assertStringContainsString('retention_hold', $evidence($a, false));
        $this->assertStringContainsString('retention_hold', $this->asRetention($a['school'], fn () => $expiry->payrollRun($a['school'], $a['run']->id, $cutoff, true)));
        $kept = $payroll->pruneEvidence($a['school'], $cutoff->toDateString(), 100, false);
        $this->assertSame([1, 0, 1, 0], [$kept['eligible'], $kept['deleted'], $kept['dependency_blocked'], $kept['errors']], 'kept: the database refused on the hold');
        $this->assertSame(1, $this->rowsWhere($a['school'], 'payroll_run_results', 'id', $a['result']));
        $this->assertSame('', $evidence($b, true), 'another School is independent');

        // The platform hold blocks every School.
        $holds->place(null, 'regulatory_inquiry', 'PAY-HOLD-2');
        $this->assertStringContainsString('retention_hold', $evidence($b, true));
        $holds->release(null, 'inquiry_closed', 'PAY-HOLD-2');
        $holds->release($a['school']->id, 'matter_concluded', 'PAY-HOLD-1');

        // Released: both phases, every statement on the one retention session, each attributed.
        Log::spy();
        $used = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$used): void {
            $used[] = $e->connectionName;
        });
        $done = $payroll->pruneEvidence($a['school'], $cutoff->toDateString(), 100, false);
        $runs = $payroll->pruneRuns($a['school'], $cutoff, 100, false);
        $this->assertSame([1, 0], [$done['deleted'], $done['errors']]);
        $this->assertSame([1, 0], [$runs['deleted'], $runs['errors']]);
        $this->assertSame([RetentionExpiry::PRIVILEGED_CONNECTION], array_values(array_unique($used)));
        Log::shouldHaveReceived('info')->with('retention.unit_run', [
            'category' => 'payroll_evidence', 'school_id' => $a['school']->id, 'identity' => 'school_os_retention', 'dry_run' => false,
            'eligible' => 1, 'deleted' => 1, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0,
        ])->once();
        $this->assertSame(0, $this->rowsWhere($a['school'], 'payroll_run_results', 'id', $a['result']));
        $this->assertSame(0, $this->rowsWhere($a['school'], 'payroll_runs', 'id', $a['run']->id));
        $this->assertSame(1, $this->rowsWhere($b['school'], 'payroll_run_results', 'id', $b['result']), 'B untouched');

        // RLS still applies to the retention identity (NOBYPASSRLS, not the owner): the tenant context is
        // visibility only -- another School's context, or none, sees nothing of B (fail closed).
        $visible = fn (?string $context, string $id): int => (int) DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION)->transaction(function ($c) use ($context, $id) {
            if ($context !== null) {
                $c->select("SELECT set_config('app.current_school_id', ?, true)", [$context]);
            }

            return $c->selectOne('SELECT count(id) AS n FROM payroll_runs WHERE id = ?', [$id])->n;
        });
        $this->assertSame(1, $visible($b['school']->id, $b['run']->id));
        $this->assertSame(0, $visible($a['school']->id, $b['run']->id), "another School's context sees nothing");
        $this->assertSame(0, $visible(null, $b['run']->id), 'no context: nothing');

        // Raw identity: the session is the retention login, unprivileged outside the definer ...
        $identity = DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION)->selectOne('SELECT session_user::text AS s, current_user::text AS c');
        $this->assertSame(['school_os_retention', 'school_os_retention'], [$identity->s, $identity->c]);
        // ... where it can neither delete nor even lock payroll rows (the freeze guard needs the owner's
        // privileges, which only the definer's current_user holds) -- yet the function's deletes passed it.
        $this->assertStringContainsString('permission denied', $this->asRetention($b['school'], function () use ($b): void {
            DB::statement("SELECT set_config('app.payroll_retention', 'on', true)");
            DB::table('payroll_run_results')->where('id', $b['result'])->delete();
        }));
        $this->assertStringContainsString('permission denied', $this->asRetention($b['school'], fn () => DB::table('payroll_runs')->where('id', $b['run']->id)->lockForUpdate()->first(['id'])));
        $this->assertStringContainsString('permission denied', $this->asRetention($b['school'], fn () => DB::table('payroll_runs')->where('id', $b['run']->id)->update(['results_expired_at' => now()])));
    }

    #[Test]
    public function the_functions_are_narrow_definers_executable_by_the_retention_identity_only(): void
    {
        $rows = DB::select(
            "SELECT p.proname, p.prosecdef, array_to_string(p.proconfig, ',') AS config, p.prosrc AS src,
                    has_function_privilege('school_os_app', p.oid, 'EXECUTE') AS runtime,
                    has_function_privilege('school_os_retention', p.oid, 'EXECUTE') AS retention,
                    EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public
               FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace
                AND p.proname IN ('retention_expire_payroll_employee_evidence', 'retention_expire_payroll_run', 'retention_assert_payroll_employee_floor')
              ORDER BY p.proname",
        );
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertFalse((bool) $row->public, "{$row->proname}: never PUBLIC");
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', (string) $row->config, "{$row->proname}: pinned search_path");
            $expected = $row->proname !== 'retention_assert_payroll_employee_floor';
            $this->assertSame($expected, (bool) $row->prosecdef, "{$row->proname}: definer only where it deletes");
            // E21-RH.5: the runtime role executes none of them; the retention identity only the two definers.
            $this->assertFalse((bool) $row->runtime, "{$row->proname}: not callable by the runtime role");
            $this->assertSame($expected, (bool) $row->retention, "{$row->proname}: the floor helper is not callable by the retention identity");
            if ($expected) {
                $this->assertStringContainsString("PERFORM public.retention_assert_retention_identity();\n    PERFORM public.retention_assert_not_held(p_school_id);", (string) $row->src, "{$row->proname}: identity and hold first");
            }
        }
    }

    #[Test]
    public function finance_still_refuses_an_entry_a_payroll_posting_references(): void
    {
        $w = $this->posted();
        $this->closeOldYear($w['school']);
        $entries = $this->runEntries($w['school'], $w['run']->id);

        $this->assertStringContainsString('retention_finance_dependency', $this->refusal($w['school'], fn () => app(RetentionExpiry::class)->financeUnit($w['school'], [], $entries, true)));
    }

    #[Test]
    public function only_the_payroll_retention_service_reaches_the_functions_and_only_its_command_reaches_the_service(): void
    {
        $this->assertSame(['Domain/Payroll/Application/Retention/PayrollEvidenceRetentionService.php', 'Support/Retention/RetentionExpiry.php'], $this->filesContaining('payrollEmployeeEvidence('));
        $this->assertSame(['Domain/Payroll/Application/Retention/PayrollEvidenceRetentionService.php'], $this->filesContaining('->payrollRun('));
        $this->assertSame(['Console/Commands/PrunePayrollEvidence.php', 'Domain/Payroll/Application/Retention/PayrollEvidenceRetentionService.php'], $this->filesContaining('PayrollEvidenceRetentionService'));

        $command = (string) file_get_contents(app_path('Console/Commands/PrunePayrollEvidence.php'));
        $this->assertStringContainsString("config('retention.employee_evidence_years')", $command, 'one D9 setting: never a second, independently configurable payroll period');
        $this->assertStringNotContainsString('--employee', $command, 'no force-delete of a named Employee');
        $this->assertStringNotContainsString('--run', $command);

        // Payroll never deletes a journal entry; Finance alone does.
        foreach (['Domain/Payroll/Application/Retention/PayrollEvidenceRetentionService.php', 'Console/Commands/PrunePayrollEvidence.php'] as $file) {
            $this->assertStringNotContainsString('journal_entries', $this->code(app_path($file)), "{$file}");
        }
        $migration = (string) file_get_contents(database_path('migrations/2026_11_15_090000_add_payroll_evidence_retention.php'));
        $this->assertStringNotContainsString('DELETE FROM public.journal', $migration);
        $this->assertStringNotContainsString('GRANT DELETE', $migration, 'no runtime privilege is widened');
    }

    #[Test]
    public function ordinary_payroll_code_keeps_its_immutability_and_never_deletes_posted_evidence(): void
    {
        // The only application deletes on payroll results are the draft recalculation; adjustments
        // and postings are append-only by privilege and never deleted by application code.
        $this->assertSame(['Domain/Payroll/Application/PayrollRunService.php'], $this->filesContaining('PayrollRunResult::query()->where(\'payroll_run_id\', $run->id)->delete()'));
        foreach (['payroll_adjustments', 'payroll_run_postings', 'payroll_statutory_run_postings', 'payroll_lwf_annual_charges'] as $table) {
            foreach ($this->filesContaining("'{$table}'") as $file) {
                $this->assertDoesNotMatchRegularExpression("/table\\('{$table}'\\)[^;]*->delete\\(/", $this->code(app_path($file)), "{$file} never deletes {$table}");
            }
        }
    }
}
