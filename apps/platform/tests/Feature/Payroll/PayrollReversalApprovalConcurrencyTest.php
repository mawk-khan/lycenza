<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C3 (PAY-13), genuinely separate OS processes, the holder held open and
 * the contender observed blocked on a lock in pg_stat_activity: a reversal holds
 * the School's compensation lock EXCLUSIVELY, an approval or calculation holds it
 * SHARED, so each is ordered wholly before or after the reversal without either
 * locking the other's run. Every date is real: the child processes use the clock.
 */
class PayrollReversalApprovalConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    /** @var array<string, mixed> */
    private array $setup;

    private EmploymentRecord $record;

    private User $preparer;

    private User $checker;

    private User $x;

    private User $finance;

    private PayrollRun $october;

    private PayrollRun $november;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->deleteSchoolAsAdmin($this->school);
            } catch (\Throwable) {
                // Approved runs' frozen results make the School hard to delete; residue is reset between sessions.
            }
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $employee = $this->paidEmployee($this->school, $this->setup);
        $this->record = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail());
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $this->checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view']);

        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, $this->record->fresh(), $this->setup['structure'], Carbon::parse('2026-08-01'),
            [new FixedComponentValueInput($this->setup['line'], '55000.00')], $this->x, $this->freshMfaProofFor($this->x),
        ));
        $this->october = $this->calculatedRun('2026-08-01');
        $this->inSchool($this->school, function (): void {
            $runs = app(PayrollRunService::class);
            $run = $this->october->fresh();
            $runs->approve($run, $this->checker, $this->freshMfaProofFor($this->checker), $runs->latestCalculationId($run), $runs->latestAttributionVersion($run));
            app(PayrollPostingService::class)->post($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance));
        });
        $this->november = $this->calculatedRun('2026-09-01');
    }

    private function calculatedRun(string $month): PayrollRun
    {
        return $this->inSchool($this->school, function () use ($month): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $this->preparer), $this->preparer);
            $run = app(PayrollRunService::class)->createRun($period, $this->preparer);
            app(PayrollRunService::class)->calculate($run, $this->preparer);

            return $run->fresh();
        });
    }

    /** @return list<string> */
    private function reverseCommand(): array
    {
        return ['php', __DIR__.'/../../Support/reverse-payroll-run.php', $this->school->id, $this->october->id, $this->finance->id];
    }

    /** @return list<string> */
    private function approveCommand(User $approver): array
    {
        return ['php', __DIR__.'/../../Support/approve-payroll-run.php', $this->school->id, $this->november->id, $approver->id];
    }

    /** @return list<string> */
    private function compensationAuthors(PayrollRun $run): array
    {
        return $this->inSchool($this->school, fn () => app(PayrollRunService::class)->participants($run->fresh())->all()['compensation_author'] ?? []);
    }

    private function runStatus(PayrollRun $run): string
    {
        return (string) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $run->id)->value('status'));
    }

    private function reversals(): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $this->october->id)->where('posting_kind', 'reversal')->count());
    }

    #[Test]
    public function a_reversal_held_open_refuses_the_author_waiting_to_approve(): void
    {
        $this->assertSame([], $this->compensationAuthors($this->november));

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand(), $this->approveCommand($this->x));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\SelfApprovalNotAllowedException', $contender, 'the approval waited, then saw the reversal');
        $this->assertSame('calculated', $this->runStatus($this->november));
        $this->assertSame([$this->x->id], $this->compensationAuthors($this->november));
    }

    #[Test]
    public function a_reversal_held_open_makes_the_waiting_independent_checkers_packet_stale(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand(), $this->approveCommand($this->checker));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\StaleRunReviewException', $contender, 'its attribution was read before the reversal committed');
        $this->assertSame('calculated', $this->runStatus($this->november));

        $packet = $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $this->november->id, $this->checker));
        $this->assertSame(1, $packet['compensation']->total(), 'the reopened review shows the change');
    }

    #[Test]
    public function an_approval_held_open_is_ordered_before_the_waiting_reversal_and_stands(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->approveCommand($this->x), $this->reverseCommand());

        $this->assertSame('approved', $holder, 'October stood when this decision was taken');
        $this->assertStringStartsWith('reversed:', $contender);
        $this->assertSame('approved', $this->runStatus($this->november), 'a completed decision stands');
        $this->assertSame(1, $this->reversals());

        // The explanation stays reproducible, and the author's own approval never stands in for an independent one.
        $this->assertSame((string) $this->x->id, (string) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $this->november->id)->value('approved_by_user_id')));
        $december = $this->calculatedRun('2026-10-01');
        $this->assertSame([$this->x->id], $this->compensationAuthors($december));
    }

    #[Test]
    public function a_reversal_held_open_orders_a_waiting_recalculation_after_it_without_refusing_it(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand(), ['php', __DIR__.'/../../Support/calculate-payroll-run.php', $this->school->id, $this->november->id, $this->preparer->id]);

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertStringStartsWith('calculated:', $contender, 'a reversal never forces or refuses a recalculation');
        $this->assertSame([$this->x->id], $this->compensationAuthors($this->november));
    }

    #[Test]
    public function two_reversals_of_one_run_leave_exactly_one(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand(), $this->reverseCommand());

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\PayrollRunAlreadyReversedException', $contender);
        $this->assertSame(1, $this->reversals());
    }

    #[Test]
    public function a_raw_reversal_posting_takes_the_lock_too(): void
    {
        $trigger = DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname = 'trg_payroll_run_postings_reversal_lock'");
        $this->assertSame(1, (int) $trigger->c);
    }

    #[Test]
    public function an_authorless_change_reports_its_gap_and_adds_nobody(): void
    {
        // A legacy row: only the schema owner can leave an author unset (the runtime role is refused).
        DB::connection('pgsql_admin')->table('employee_compensation_assignments')->where('school_id', $this->school->id)->update(['assigned_by_user_id' => null]);
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($this->october->fresh(), $this->finance, $this->freshMfaProofFor($this->finance), 'test'));

        $this->assertSame([], $this->compensationAuthors($this->november), 'an unknown author is never guessed');
        $packet = $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $this->november->id, $this->checker));
        $codes = array_column($packet['warnings'], 'code');
        $this->assertContains('PAYROLL_COMPENSATION_AUTHOR_NOT_RECORDED', $codes);
        $this->assertContains('PAYROLL_PREVIOUS_RUN_REVERSED', $codes);
    }
}
