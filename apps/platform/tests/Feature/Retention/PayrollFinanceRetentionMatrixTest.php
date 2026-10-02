<?php

namespace Tests\Feature\Retention;

use App\Domain\Finance\Application\Periods\FinanceRetentionReadiness;
use App\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3F (E21-D9 x E21-D8, ADR 0064 §21 amended): the golden cross-policy
 * matrix. Payroll-linked journal detail may leave only when the payroll
 * evidence no longer needs it (D9) AND its financial period is itself 8
 * years closed (D8); the longest period always wins, and no financial
 * reading changes.
 *
 * - A: D9 due, D8 due -> payroll evidence expires, the run releases its
 *   entries, and the NEXT Finance run removes them; the Employee is then
 *   released by the next Employee run.
 * - B: D9 due, D8 not due (its year closed today) -> payroll evidence
 *   expires, the entry stays with Finance.
 * - C: D8 due, D9 not due (separated in 2022) -> everything stays; Finance
 *   reports the entry dependency-blocked.
 * - D: rehired, current -> everything stays.
 */
class PayrollFinanceRetentionMatrixTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePayrollRetention();
        $this->enableFinanceRetention();
    }

    /** A paid Employee in a run of August 2015 (FY 2015-16), with a Student whose 1000.00 fee was paid in full. */
    private function world(string $runMonth = '2015-08-01', string $postedOn = '2015-08-31'): array
    {
        $this->travelTo(Carbon::parse('2015-06-15 06:00:00', 'UTC'));
        $w = $this->concessionWorld();
        $this->pay($w, '1000.00', null, $w['charge']);
        $this->travelBack();
        $setup = $this->payrollSetup($w['school']);
        $this->statutorySetup($w['school'], $setup);
        $w['employee'] = $this->paidEmployee($w['school'], $setup);
        $w['run'] = $this->postedRun($w['school'], $runMonth, $postedOn, statutory: true);
        $w['entries'] = $this->runEntries($w['school'], $w['run']->id);

        return $w;
    }

    private function financePrune(School $school): PendingCommand
    {
        return $this->artisan('platform:finance-retention-prune', ['--school' => $school->id]);
    }

    private function entriesLeft(array $w): int
    {
        return $this->inSchool($w['school'], fn () => DB::table('journal_entries')->whereIn('id', $w['entries'])->count());
    }

    #[Test]
    public function d9_due_and_d8_due_payroll_detail_leaves_through_finance_on_its_next_run_and_nothing_changes(): void
    {
        $w = $this->world();
        $this->separate($w['employee'], '2015-12-31');
        $this->closeOldYear($w['school']);
        $golden = $this->golden($w, [$w['charge']->id]);

        // Finance first: the payroll posting still claims the entries (payroll_evidence_retained).
        $this->financePrune($w['school'])->expectsOutputToContain('blocked=2 errors=0 verification_failed=0')->assertSuccessful();
        $this->assertSame(2, $this->entriesLeft($w));
        $this->assertSame(['dependency_blocked'], app(FinanceRetentionReadiness::class)->assess($w['school'])['periods']['2015-16']);

        $this->artisan('platform:payroll-retention-prune')->expectsOutputToContain('Deleted 1 emptied payroll run(s)')->assertSuccessful();
        $this->assertSame(2, $this->entriesLeft($w), 'Payroll released the entries; it never deletes them');
        $this->assertSame($golden, $this->golden($w, [$w['charge']->id]));

        // The next Finance run owns them: D8 is due, so they expire, with no reading changed.
        $this->financePrune($w['school'])->expectsOutputToContain('deleted=2 held=0 blocked=0 errors=0 verification_failed=0')->assertSuccessful();
        $this->assertSame(0, $this->entriesLeft($w));
        $this->assertSame($golden, $this->golden($w, [$w['charge']->id]), 'every account balance (payroll accounts included), charge outstanding, Student due and receipt series is exactly unchanged');
        $this->assertSame([], $this->verifyBalances($w['school']));

        // The next Employee run releases the Employee.
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->assertSuccessful();
        $this->assertSame(0, $this->rowsWhere($w['school'], 'employees', 'id', $w['employee']->id));
    }

    #[Test]
    public function d9_due_and_d8_not_due_the_payroll_evidence_expires_and_the_entry_stays_with_finance(): void
    {
        $w = $this->world('2016-06-01', '2016-06-30');
        $this->separate($w['employee'], '2016-07-31');
        $this->closeOldYear($w['school']);
        $this->closePeriod(['school' => $w['school'], 'closer' => $this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.periods.manage'])], $this->periodByKey($w['school'], '2016-17'));
        $golden = $this->golden($w, [$w['charge']->id]);

        $this->artisan('platform:payroll-retention-prune')
            ->expectsOutputToContain('Deleted the payroll evidence of 1 Employee(s)')
            ->expectsOutputToContain('Deleted 1 emptied payroll run(s)')
            ->assertSuccessful();
        $this->financePrune($w['school'])->assertSuccessful();

        $this->assertSame(2, $this->entriesLeft($w), 'FY 2016-17 closed today: D8 keeps the detail');
        $this->assertSame(0, $this->rowsWhere($w['school'], 'payroll_run_results', 'employee_id', $w['employee']->id));
        $this->assertSame($golden, $this->golden($w, [$w['charge']->id]));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function d8_due_and_d9_not_due_everything_stays_and_finance_reports_the_entry_dependency_blocked(): void
    {
        $w = $this->world();
        $this->separate($w['employee'], '2022-03-31');
        $this->closeOldYear($w['school']);

        $this->artisan('platform:payroll-retention-prune')->expectsOutputToContain('Deleted the payroll evidence of 0 Employee(s)')->assertSuccessful();
        $this->financePrune($w['school'])->expectsOutputToContain('blocked=2')->assertSuccessful();

        $this->assertSame(2, $this->entriesLeft($w));
        $this->assertSame(1, $this->rowsWhere($w['school'], 'payroll_run_results', 'employee_id', $w['employee']->id));
        $this->assertSame(1, $this->rowsWhere($w['school'], 'payroll_runs', 'id', $w['run']->id));
        $this->assertSame(['dependency_blocked'], app(FinanceRetentionReadiness::class)->assess($w['school'])['periods']['2015-16']);
    }

    #[Test]
    public function a_rehired_current_employee_keeps_everything(): void
    {
        $w = $this->world();
        $this->separate($w['employee'], '2015-12-31');
        $this->createEmploymentRecord($w['employee'], ['status' => 'active', 'starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->closeOldYear($w['school']);

        $this->artisan('platform:payroll-retention-prune')->expectsOutputToContain('Deleted the payroll evidence of 0 Employee(s)')->assertSuccessful();
        $this->financePrune($w['school'])->expectsOutputToContain('blocked=2')->assertSuccessful();
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->assertSuccessful();

        $this->assertSame(2, $this->entriesLeft($w));
        $this->assertSame(1, $this->rowsWhere($w['school'], 'payroll_run_results', 'employee_id', $w['employee']->id));
        $this->assertSame(1, $this->rowsWhere($w['school'], 'employees', 'id', $w['employee']->id));
    }
}
