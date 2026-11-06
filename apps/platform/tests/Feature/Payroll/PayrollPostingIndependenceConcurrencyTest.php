<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C4 (PAY-16), genuinely separate OS processes, the holder held open and
 * the contender observed blocked on a lock: posting holds the School's
 * compensation lock SHARED and a reversal EXCLUSIVELY, so each is ordered wholly
 * before or after the other, and neither locks the other's run.
 *
 * August pays X's raise for E1. September pays X's raise for E2 and still prices
 * E1. X approves October, which both stand behind. Reversing September leaves
 * E2's raise with no standing approval (one implicated change); reversing August
 * as well implicates E1's too, which changes the evidence.
 */
class PayrollPostingIndependenceConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    /** @var array<string, mixed> */
    private array $setup;

    private User $preparer;

    private User $checker;

    private User $x;

    private User $finance;

    private User $poster;

    private PayrollRun $august;

    private PayrollRun $september;

    private PayrollRun $october;

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
        $first = $this->paidEmployee($this->school, $this->setup);
        $second = $this->paidEmployee($this->school, $this->setup);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $this->checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view']);
        $this->poster = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);

        $this->raise($first->id, '2026-08-01', '55000.00');
        $this->august = $this->calculatedRun('2026-08-01');
        $this->approveAndPost($this->august);
        $this->raise($second->id, '2026-09-01', '56000.00');
        $this->september = $this->calculatedRun('2026-09-01');
        $this->approveAndPost($this->september);
        $this->october = $this->calculatedRun('2026-10-01');
        $this->inSchool($this->school, function (): void {
            $runs = app(PayrollRunService::class);
            $run = $this->october->fresh();
            $runs->approve($run, $this->x, $this->freshMfaProofFor($this->x), $runs->latestCalculationId($run), $runs->latestAttributionVersion($run));
        });
    }

    private function raise(string $employeeId, string $from, string $amount): void
    {
        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $employeeId)->firstOrFail(), $this->setup['structure'], Carbon::parse($from),
            [new FixedComponentValueInput($this->setup['line'], $amount)], $this->x, $this->freshMfaProofFor($this->x),
        ));
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

    private function approveAndPost(PayrollRun $run): void
    {
        $this->inSchool($this->school, function () use ($run): void {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();
            $runs->approve($fresh, $this->checker, $this->freshMfaProofFor($this->checker), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
            app(PayrollPostingService::class)->post($fresh->fresh(), $this->finance, $this->freshMfaProofFor($this->finance));
        });
    }

    private function reverse(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance), 'test'));
    }

    /** @return list<string> */
    private function reverseCommand(PayrollRun $run): array
    {
        return ['php', __DIR__.'/../../Support/reverse-payroll-run.php', $this->school->id, $run->id, $this->finance->id];
    }

    /** @return list<string> */
    private function postCommand(?string $version = null): array
    {
        return array_values(array_filter(['php', __DIR__.'/../../Support/post-payroll-run.php', $this->school->id, $this->october->id, $this->poster->id, $version]));
    }

    private function version(): ?string
    {
        return $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($this->october->fresh()));
    }

    private function octoberStatus(): string
    {
        return (string) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $this->october->id)->value('status'));
    }

    private function attestations(): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $this->october->id)->count());
    }

    #[Test]
    public function a_reversal_held_open_makes_the_waiting_posting_need_an_attestation(): void
    {
        $this->assertNull($this->version(), 'nothing to attest before the reversal');

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand($this->september), $this->postCommand());

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\PostingAttestationRequiredException', $contender, 'the posting waited, then saw the reversal');
        $this->assertSame('approved', $this->octoberStatus());
    }

    #[Test]
    public function a_posting_held_open_is_ordered_before_the_waiting_reversal_which_then_records_it(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->postCommand(), $this->reverseCommand($this->september));

        $this->assertStringStartsWith('posted:', $holder, 'independence stood when this was posted');
        $this->assertStringStartsWith('reversed:', $contender, 'the reversal is never blocked');
        $this->assertSame('posted', $this->octoberStatus());
        $this->assertSame(0, $this->attestations());
        $this->assertSame(1, $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.independence_changed')->where('subject_id', $this->october->id)->count()), 'PAY-18: recorded for reconciliation');
    }

    #[Test]
    public function a_reversal_held_open_makes_a_waiting_attestation_stale(): void
    {
        $this->reverse($this->september);
        $reviewed = $this->version();
        $this->assertNotNull($reviewed);

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand($this->august), $this->postCommand($reviewed));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\PostingEvidenceStaleException', $contender, 'the evidence changed while it waited');
        $this->assertNotSame($reviewed, $this->version());
        $this->assertSame([0, 'approved'], [$this->attestations(), $this->octoberStatus()]);
    }

    #[Test]
    public function an_attested_posting_held_open_is_ordered_before_the_waiting_reversal(): void
    {
        $this->reverse($this->september);

        [$holder, $contender] = $this->raceWithHeldHolder($this->postCommand($this->version()), $this->reverseCommand($this->august));

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertStringStartsWith('reversed:', $contender);
        $this->assertSame([1, 'posted'], [$this->attestations(), $this->octoberStatus()]);
    }

    #[Test]
    public function two_attested_postings_of_one_run_leave_exactly_one(): void
    {
        $this->reverse($this->september);
        $version = $this->version();

        [$holder, $contender] = $this->raceWithHeldHolder($this->postCommand($version), $this->postCommand($version));

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(1, $this->attestations());
        $this->assertSame(1, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $this->october->id)->where('posting_kind', 'original')->count()));
    }

    #[Test]
    public function the_review_works_on_committed_data_outside_any_transaction(): void
    {
        // Runs with no wrapping transaction, like a page load: the decision-only fresh read must never be reached here.
        $this->reverse($this->september);

        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($this->october->fresh(), $this->poster, true));

        $this->assertSame([0, 'before_posting', null, 1], [DB::transactionLevel(), $review['phase'], $review['blockedReason'], $review['details']['changes']['total']]);
    }

    #[Test]
    public function an_authorless_change_adds_no_exception(): void
    {
        DB::connection('pgsql_admin')->table('employee_compensation_assignments')->where('school_id', $this->school->id)->update(['assigned_by_user_id' => null]);
        $this->reverse($this->september);

        $this->assertNull($this->version(), 'an unknown author can never equal the approver; recorded as a limit, never guessed');
    }
}
