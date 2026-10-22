<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * ORG.8B (ADR 0076 §60): as the real runtime role, a raw approval of a
 * payroll run is refused by `payroll_runs_participant_approval_guard` when
 * the recorded approver created, calculated or adjusted the run, or when its
 * results are stale (an adjustment after the last recorded calculation, or
 * no recorded calculation); a non-participant approving calculated results
 * stands. `payroll_run_calculations` is append-only, run-bound and checks its
 * own adjustment count; the installed objects are pinned.
 */
class PayrollParticipantApprovalDbTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $employee = $this->paidEmployee($school, $setup);
        $creator = $this->createUser();
        $calculator = $this->createUser();

        $this->travelTo(Carbon::parse('2015-08-10 06:00:00'));
        $run = $this->inSchool($school, function () use ($school, $creator, $calculator): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse('2015-08-01'), null, $creator), $creator);
            $run = app(PayrollRunService::class)->createRun($period, $creator);
            app(PayrollRunService::class)->calculate($run, $calculator);

            return $run->fresh();
        });
        $this->travelBack();

        return ['school' => $school, 'run' => $run, 'creator' => $creator, 'calculator' => $calculator, 'employee' => $employee];
    }

    private function runtime(School $school): Connection
    {
        $db = DB::connection('pgsql');
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        return $db;
    }

    /** A raw runtime approval in a savepoint: the rows changed, or the refusal message. */
    private function approve(array $w, string $approverId): int|string
    {
        $db = $this->runtime($w['school']);
        try {
            return $db->transaction(function () use ($db, $w, $approverId) {
                $rows = $db->update("update payroll_runs set status = 'approved', approved_by_user_id = ?, approved_at = now() where id = ?", [$approverId, $w['run']->id]);
                throw new \RuntimeException((string) $rows);
            });
        } catch (QueryException $e) {
            return $e->getMessage();
        } catch (\RuntimeException $e) {
            return (int) $e->getMessage();
        }
    }

    private function adjust(array $w, string $authorId): void
    {
        $record = $this->inSchool($w['school'], fn () => EmploymentRecord::query()->where('employee_id', $w['employee']->id)->firstOrFail());
        $basic = $this->inSchool($w['school'], fn () => SalaryComponent::query()->where('code', 'BASIC')->firstOrFail()->id);
        $this->inSchool($w['school'], fn () => app(PayrollRunService::class)->recordManualOverride($w['run']->fresh(), $record, [$basic => '41000.00'], 'Joined mid-month', User::query()->findOrFail($authorId)));
    }

    #[Test]
    public function the_database_refuses_every_participant_and_stale_results(): void
    {
        $w = $this->world();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');

        foreach (['creator', 'calculator'] as $participant) {
            $this->assertStringContainsString('is approved by someone who prepared it', (string) $this->approve($w, $w[$participant]->id), $participant);
        }
        $outsider = $this->createUser();
        $this->assertSame(1, $this->approve($w, $outsider->id), 'a non-participant approving calculated results stands');

        $author = $this->createUser();
        $this->adjust($w, $author->id);
        $this->assertStringContainsString('is approved by someone who prepared it', (string) $this->approve($w, $author->id), 'adjustment author');
        $this->assertStringContainsString('changed after its last recorded calculation', (string) $this->approve($w, $outsider->id), 'stale results');
    }

    #[Test]
    public function the_calculation_record_is_append_only_run_bound_and_honest(): void
    {
        $w = $this->world();
        $db = $this->runtime($w['school']);
        $refused = function (callable $statement) use ($db): string {
            try {
                $db->transaction($statement);

                return '';
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        };

        $this->assertStringContainsString('permission denied', $refused(fn () => $db->table('payroll_run_calculations')->where('payroll_run_id', $w['run']->id)->delete()));
        $this->assertStringContainsString('permission denied', $refused(fn () => $db->table('payroll_run_calculations')->where('payroll_run_id', $w['run']->id)->update(['calculated_by_user_id' => $this->createUser()->id])));
        $this->assertStringContainsString('adjustment_count must be', $refused(fn () => $db->table('payroll_run_calculations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'payroll_run_id' => $w['run']->id, 'calculated_by_user_id' => $w['creator']->id, 'ordinal' => 2, 'adjustment_count' => 5, 'complete' => true,
        ])));
        $this->assertStringContainsString('ordinal must follow', $refused(fn () => $db->table('payroll_run_calculations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'payroll_run_id' => $w['run']->id, 'calculated_by_user_id' => $w['creator']->id, 'ordinal' => 7, 'adjustment_count' => 0, 'complete' => true,
        ])));

        foreach (['payroll_run_calculations_guard', 'payroll_run_has_participant', 'payroll_runs_refuse_participant_approval', 'payroll_adjustments_require_preparing_run', 'payroll_runs_freeze_approval'] as $function) {
            $fn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, has_function_privilege('public', p.oid, 'EXECUTE') as public_exec from pg_proc p where p.proname = ?", [$function]);
            $this->assertFalse($fn->prosecdef);
            $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', $fn->config);
            $this->assertFalse($fn->public_exec);
        }
        $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'public.payroll_run_calculations'::regclass");
        $this->assertTrue($rls->relrowsecurity && $rls->relforcerowsecurity);
        $this->assertNotNull(DB::selectOne("select 1 as ok from pg_constraint where conname = 'payroll_runs_sod_check'"), 'the creator CHECK is kept');
    }

    #[Test]
    public function an_incomplete_latest_calculation_is_refused_and_a_decision_is_final(): void
    {
        $w = $this->world();
        $outsider = $this->createUser();
        $db = $this->runtime($w['school']);
        // A later recalculation that left an employment unresolved (the run stays calculated).
        $db->table('payroll_run_calculations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'payroll_run_id' => $w['run']->id,
            'calculated_by_user_id' => $this->createUser()->id, 'ordinal' => 2, 'adjustment_count' => 0, 'complete' => false,
        ]);
        $this->assertStringContainsString('changed after its last recorded calculation', (string) $this->approve($w, $outsider->id), 'the LATEST calculation decides');

        $complete = $this->createUser();
        $db->table('payroll_run_calculations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'payroll_run_id' => $w['run']->id,
            'calculated_by_user_id' => $complete->id, 'ordinal' => 3, 'adjustment_count' => 0, 'complete' => true,
        ]);
        $db->update("update payroll_runs set status = 'approved', approved_by_user_id = ?, approved_at = now() where id = ?", [$outsider->id, $w['run']->id]);

        $refused = function (callable $statement) use ($db): string {
            try {
                $db->transaction($statement);

                return '';
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        };
        $this->assertStringContainsString('approval is final', $refused(fn () => $db->update('update payroll_runs set approved_by_user_id = ? where id = ?', [$complete->id, $w['run']->id])));
        $record = $this->inSchool($w['school'], fn () => EmploymentRecord::query()->where('employee_id', $w['employee']->id)->firstOrFail());
        $basic = $this->inSchool($w['school'], fn () => SalaryComponent::query()->where('code', 'BASIC')->firstOrFail()->id);
        $this->assertStringContainsString('it is not being prepared', $refused(fn () => $db->table('payroll_adjustments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'payroll_run_id' => $w['run']->id, 'employment_record_id' => $record->id,
            'salary_component_id' => $basic, 'mode' => 'manual_override', 'amount' => '1.00', 'reason' => 'raw', 'actor_user_id' => $outsider->id,
            'created_at' => now(), 'updated_at' => now(),
        ])));
    }
}
