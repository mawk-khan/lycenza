<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C1 (PAY-2), two real PostgreSQL sessions, the holder held open
 * (HeldTransaction) and the contender OBSERVED blocked in pg_stat_activity
 * before the holder commits: a compensation write and a payroll approval of
 * the same School are serialized by the School's compensation lock
 * (exclusive / shared), so a freshness check is never overtaken.
 */
class PayrollCompensationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    /** @var array{run: PayrollRun, record: EmploymentRecord, structure: string, line: string, preparer: User} */
    private array $world;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->deleteSchoolAsAdmin($this->school);
            } catch (\Throwable) {
                // An approved run's frozen results make the School hard to delete; residue is reset between sessions.
            }
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $preparer = $this->createUser();
        $this->world = app(TenantContext::class)->withSchool($this->school, function () use ($preparer): array {
            $structures = app(SalaryStructureService::class);
            $structure = $structures->createDraft($this->school, 'GRADE-CC', 'Grade concurrency', $preparer);
            $basic = app(SalaryComponentService::class)->create($this->school, 'BASIC', 'Basic', 'earning', null, $preparer);
            $line = $structures->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
            $structure = $structures->activate($structure, $preparer);
            $employee = Employee::factory()->for($this->school, 'school')->create();
            $record = EmploymentRecord::factory()->create(['school_id' => $this->school->id, 'employee_id' => $employee->id, 'starts_on' => '2025-01-01']);
            app(CompensationService::class)->assign($this->school, $record, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($line->id, '50000.00')], $preparer, $this->freshMfaProofFor($preparer));
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);
            $run = app(PayrollRunService::class)->createRun($period, $preparer);
            app(PayrollRunService::class)->calculate($run, $preparer);

            return ['run' => $run->fresh(), 'record' => $record, 'structure' => $structure->id, 'line' => $line->id, 'preparer' => $preparer];
        });
    }

    /** @return list<string> */
    private function assignCommand(User $author, string $effectiveFrom): array
    {
        return ['php', __DIR__.'/../../Support/hold-compensation-assignment.php', $this->school->id, $this->world['record']->id,
            $this->world['structure'], $this->world['line'], '61000.00', $effectiveFrom, $author->id];
    }

    /** @return list<string> */
    private function approveCommand(User $approver): array
    {
        return ['php', __DIR__.'/../../Support/approve-payroll-run.php', $this->school->id, $this->world['run']->id, $approver->id];
    }

    private function runState(): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => (array) DB::table('payroll_runs')->where('id', $this->world['run']->id)->first(['status', 'approved_calculation_id']));
    }

    #[Test]
    public function a_compensation_write_held_open_makes_the_waiting_approval_refuse_the_now_stale_calculation(): void
    {
        $author = $this->createUser();
        $approver = $this->createUser();

        [$holder, $contender] = $this->raceWithHeldHolder($this->assignCommand($author, '2026-09-16'), $this->approveCommand($approver));

        $this->assertSame('assigned', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\RunRecalculationRequiredException', $contender,
            'the approval waited for the compensation write, then saw its period change');
        $this->assertSame(['status' => 'calculated', 'approved_calculation_id' => null], $this->runState());
    }

    #[Test]
    public function an_approval_held_open_keeps_its_fresh_evidence_and_the_waiting_compensation_write_lands_after_it(): void
    {
        $author = $this->createUser();
        $approver = $this->createUser();
        $reviewed = app(PayrollRunService::class)->latestCalculationId($this->world['run']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->approveCommand($approver), $this->assignCommand($author, '2026-09-16'));

        $this->assertSame('approved', $holder);
        $this->assertSame('assigned', $contender, 'the compensation write waited for the approval, then committed');
        $this->assertSame(['status' => 'approved', 'approved_calculation_id' => $reviewed], $this->runState(),
            'the approval is bound to the calculation that was current while it decided');
    }

    #[Test]
    public function a_compensation_write_held_open_holds_back_a_recalculation_which_then_reads_the_new_compensation(): void
    {
        $author = $this->createUser();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->assignCommand($author, '2026-09-01'),
            ['php', __DIR__.'/../../Support/calculate-payroll-run.php', $this->school->id, $this->world['run']->id, $this->world['preparer']->id],
        );

        $this->assertSame('assigned', $holder);
        $this->assertStringStartsWith('calculated', $contender);
        $net = app(TenantContext::class)->withSchool($this->school, fn () => (string) DB::table('payroll_run_results')->where('payroll_run_id', $this->world['run']->id)->value('net_amount'));
        $this->assertSame('61000.00', $net, 'the recalculation read the compensation committed before it');
        $this->assertTrue(app(TenantContext::class)->withSchool($this->school, fn () => (bool) DB::selectOne(
            'select payroll_calculation_compensation_fresh(?, ?, ?) as f',
            [$this->school->id, $this->world['run']->id, app(PayrollRunService::class)->latestCalculationId($this->world['run'])],
        )->f), 'and recorded it, so it is current');
    }
}
