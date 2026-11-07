<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C15 (C14-N6, rule 86 with rule 142): Payroll writers against a real suspension, two OS
 * processes, the contender positively observed blocked on the holder's uncommitted lock.
 *
 * Every Payroll writer takes the School FOR SHARE: first in its transaction (calculation, approval,
 * compensation), or after the run row for posting and reversal, which reach it through
 * ApprovalDecisionAuthority. A suspension takes the School row FOR UPDATE and no Payroll lock, so
 * whichever commits first wins, with no cycle. Two Payroll writers never wait on each other here.
 */
class PayrollSchoolSuspensionRaceTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    protected $connectionsToTransact = [];

    private ?School $school = null;

    /** @var array<string, mixed> */
    private array $w = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $preparer = $this->createUser();
        $this->w = app(TenantContext::class)->withSchool($this->school, function () use ($preparer): array {
            $expense = LedgerAccount::factory()->for($this->school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($this->school, 'school')->type('liability')->create();
            app(PayrollAccountingConfigurationService::class)->configure($this->school, $expense->id, $payable->id, $preparer);
            $structures = app(SalaryStructureService::class);
            $structure = $structures->createDraft($this->school, 'GRADE-C15', 'Grade C15 race', $preparer);
            $basic = app(SalaryComponentService::class)->create($this->school, 'BASIC', 'Basic', 'earning', null, $preparer);
            $line = $structures->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
            $structure = $structures->activate($structure, $preparer);
            $employee = Employee::factory()->for($this->school, 'school')->create();
            $record = EmploymentRecord::factory()->create(['school_id' => $this->school->id, 'employee_id' => $employee->id, 'starts_on' => '2025-01-01']);
            app(CompensationService::class)->assign($this->school, $record, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($line->id, '50000.00')], $preparer, $this->freshMfaProofFor($preparer));

            $periods = app(PayrollPeriodService::class);
            $runs = app(PayrollRunService::class);
            $run = fn (string $month) => $runs->createRun($periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $preparer), $preparer), $preparer);
            $approved = function (string $month) use ($run, $runs, $preparer): PayrollRun {
                $r = $run($month);
                $runs->calculate($r, $preparer);
                $approver = $this->createUser();

                return $runs->approve($r->fresh(), $approver, $this->freshMfaProofFor($approver), $runs->latestCalculationId($r->fresh()), $runs->latestAttributionVersion($r->fresh()));
            };
            $poster = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse']);
            $posted = $approved('2026-06-01');
            app(PayrollPostingService::class)->post($posted->fresh(), $poster, $this->freshMfaProofFor($poster));

            return [
                'preparer' => $preparer, 'poster' => $poster, 'record' => $record->id, 'structure' => $structure->id, 'line' => $line->id,
                'draft' => $run('2026-09-01'), 'calculated' => (function () use ($run, $runs, $preparer) {
                    $r = $run('2026-08-01');
                    $runs->calculate($r, $preparer);

                    return $r->fresh();
                })(), 'approved' => $approved('2026-07-01'), 'posted' => $posted, 'nextPeriod' => $periods->createPeriod($this->school, Carbon::parse('2026-10-01'), null, $preparer),
            ];
        });
    }

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->deleteSchoolAsAdmin($this->school);
            } catch (\Throwable) {
                // A posted run's append-only rows refuse the cascade; residue is reset between sessions.
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function a_calculation_waiting_on_a_suspension_is_refused_and_writes_nothing(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->script('calculate-payroll-run.php', $this->w['draft']->id, $this->w['preparer']->id));

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame(['draft', 0], [$this->runStatus($this->w['draft']->id), $this->rows('payroll_run_calculations', $this->w['draft']->id)]);
    }

    #[Test]
    public function a_suspension_waiting_on_a_calculation_lets_it_finish_first(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('calculate-payroll-run.php', $this->w['draft']->id, $this->w['preparer']->id), $this->suspend());

        $this->assertSame('calculated:transitioned', $holder);
        $this->assertSame('suspended', $contender);
        $this->assertSame(['calculated', 'suspended'], [$this->runStatus($this->w['draft']->id), $this->schoolStatus()]);
    }

    #[Test]
    public function a_compensation_change_waiting_on_a_suspension_is_refused_without_a_deadlock(): void
    {
        $before = $this->assignments();
        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->assign());

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame($before, $this->assignments());
    }

    #[Test]
    public function a_suspension_waiting_on_a_compensation_change_lets_it_finish_first(): void
    {
        $before = $this->assignments();
        [$holder, $contender] = $this->raceWithHeldHolder($this->assign(), $this->suspend());

        $this->assertSame('assigned', $holder);
        $this->assertSame('suspended', $contender);
        $this->assertSame($before + 1, $this->assignments());
    }

    #[Test]
    public function an_approval_waiting_on_a_suspension_is_refused(): void
    {
        $runs = app(PayrollRunService::class);
        $run = app(TenantContext::class)->withSchool($this->school, fn () => $this->w['calculated']->fresh());
        [$calculation, $attribution] = app(TenantContext::class)->withSchool($this->school, fn () => [$runs->latestCalculationId($run), $runs->latestAttributionVersion($run)]);

        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->script('approve-payroll-run.php', $run->id, $this->createUser()->id, $calculation, $attribution));

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame('calculated', $this->runStatus($run->id));
    }

    #[Test]
    public function a_posting_waiting_on_a_suspension_is_refused_and_leaves_no_ledger_effect(): void
    {
        $entries = $this->journalEntries();
        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->script('post-payroll-run.php', $this->w['approved']->id, $this->w['poster']->id));

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame(['approved', 0, $entries], [$this->runStatus($this->w['approved']->id), $this->rows('payroll_run_postings', $this->w['approved']->id), $this->journalEntries()]);
    }

    #[Test]
    public function a_suspension_waiting_on_a_posting_lets_it_finish_first(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('post-payroll-run.php', $this->w['approved']->id, $this->w['poster']->id), $this->suspend());

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertSame('suspended', $contender);
        $this->assertSame([1, 'suspended'], [$this->rows('payroll_run_postings', $this->w['approved']->id), $this->schoolStatus()]);
    }

    #[Test]
    public function a_reversal_waiting_on_a_suspension_is_refused(): void
    {
        $postings = $this->rows('payroll_run_postings', $this->w['posted']->id);
        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->script('reverse-payroll-run.php', $this->w['posted']->id, $this->w['poster']->id));

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame($postings, $this->rows('payroll_run_postings', $this->w['posted']->id));
    }

    #[Test]
    public function two_payroll_writers_do_not_wait_on_each_other_for_the_school(): void
    {
        // Holder: a calculation holding the School FOR SHARE, its run row and the shared compensation lock,
        // uncommitted. Contender: a period opening, which takes the School FOR SHARE too and none of the rest.
        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $holder = new Process($this->script('calculate-payroll-run.php', $this->w['draft']->id, $this->w['preparer']->id), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(120);
        try {
            $holder->start();
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'the holder never reached its held write: '.$holder->getErrorOutput());
                usleep(2_000);
            }
            $contender = new Process($this->script('open-payroll-period.php', $this->w['nextPeriod']->id, $this->w['preparer']->id));
            $contender->setTimeout(30);
            $started = microtime(true);
            $contender->run();
            $elapsed = microtime(true) - $started;

            $this->assertTrue($holder->isRunning(), 'the holder was still uncommitted while the contender ran');
            $this->assertSame('opened', $contender->getOutput(), $contender->getErrorOutput());
            $this->assertLessThan(15.0, $elapsed, 'a Payroll writer waited on another one for the School');
            fwrite(STDERR, sprintf("\n[C15] period opening beside a held calculation: %.2f s\n", $elapsed));
        } finally {
            touch($dir.'/release');
            $holder->wait();
        }
        $this->assertSame('calculated:transitioned', $holder->getOutput());
    }

    /** @return list<string> */
    private function suspend(): array
    {
        return ['php', __DIR__.'/../../Support/suspend-school.php', $this->school->id];
    }

    /** @return list<string> */
    private function assign(): array
    {
        return ['php', __DIR__.'/../../Support/hold-compensation-assignment.php', $this->school->id, $this->w['record'], $this->w['structure'],
            $this->w['line'], '61000.00', '2026-11-01', $this->createUser()->id];
    }

    /** @return list<string> */
    private function script(string $name, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/'.$name, $this->school->id, ...$args];
    }

    private function read(callable $read): mixed
    {
        return app(TenantContext::class)->withSchool($this->school->fresh(), $read);
    }

    private function runStatus(string $id): string
    {
        return (string) $this->read(fn () => DB::table('payroll_runs')->where('id', $id)->value('status'));
    }

    private function rows(string $table, string $runId): int
    {
        return $this->read(fn () => DB::table($table)->where('payroll_run_id', $runId)->count());
    }

    private function assignments(): int
    {
        return $this->read(fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $this->w['record'])->count());
    }

    private function journalEntries(): int
    {
        return $this->read(fn () => DB::table('journal_entries')->count());
    }

    private function schoolStatus(): string
    {
        return (string) DB::table('schools')->where('id', $this->school->id)->value('status');
    }
}
