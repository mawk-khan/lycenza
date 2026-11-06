<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C7 (OD-HR-25), on COMMITTED data, in genuinely separate OS processes, with
 * the holder held open and the contender observed blocked on a lock.
 *
 * The order: a link writer takes the compensation lock EXCLUSIVELY, then the Employee
 * row; a calculation, approval, attestation or review holds the run row, then the
 * lock SHARED. So each is ordered wholly before or after the other, and no cycle
 * exists because a link writer never touches a run. An unlink cannot clear a
 * conflict in any order: it no longer changes what the check reads.
 */
class PayrollAccountLinkConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap, ProvidesSensitiveActionMfa;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const REFUSED = 'rejected:App\\Domain\\Payroll\\Application\\Exceptions\\SelfBeneficiaryConflictException';

    private ?School $school = null;

    /** @var array<string, mixed> */
    private array $setup;

    private Employee $employee;

    private User $preparer;

    private User $checker;

    private User $x;

    private User $finance;

    private User $reviewer;

    private User $hr;

    private PayrollRun $october;

    private PayrollRun $november;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $this->employee = $this->paidEmployee($this->school, $this->setup);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $this->checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->reviewer = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view', 'payroll.runs.post']);
        $this->hr = $this->fullHrActor($this->school);

        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $this->employee->id)->firstOrFail(), $this->setup['structure'], Carbon::parse('2026-10-01'),
            [new FixedComponentValueInput($this->setup['line'], '55000.00')], $this->x, $this->freshMfaProofFor($this->x),
        ));
        $this->october = $this->calculatedRun('2026-10-01');
        $this->approve($this->october, $this->checker);
        $this->postRun($this->october);
        $this->november = $this->calculatedRun('2026-11-01');
        $this->approve($this->november, $this->x);
    }

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->purgeSchoolsAsAdmin([$this->school->id]);
            } catch (\Throwable) {
                // Frozen results make the School hard to delete; residue is reset between sessions.
            }
        }
        parent::tearDown();
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

    private function approve(PayrollRun $run, User $as): void
    {
        $this->inSchool($this->school, function () use ($run, $as): void {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();
            $runs->approve($fresh, $as, $this->freshMfaProofFor($as), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
        });
    }

    private function postRun(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance)));
    }

    private function reverse(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance), 'test'));
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', $args[0], $this->school->id, ...array_slice($args, 1)];
    }

    /** @return list<string> */
    private function approveCommand(PayrollRun $run, User $as): array
    {
        return ['php', __DIR__.'/../../Support/approve-payroll-run.php', $this->school->id, $run->id, $as->id];
    }

    /** @return list<string> */
    private function calculateCommand(PayrollRun $run): array
    {
        return ['php', __DIR__.'/../../Support/payroll-approval-op.php', 'calculate', $this->school->id, $run->id, $this->preparer->id];
    }

    /** @return list<string> */
    private function attestCommand(PayrollRun $run, User $as): array
    {
        $version = $this->inSchool($this->school, fn () => (string) app(PayrollPostingIndependence::class)->version($run->fresh()));

        return ['php', __DIR__.'/../../Support/post-payroll-run.php', $this->school->id, $run->id, $as->id, $version];
    }

    /** @return list<string> */
    private function unlinkOp(): array
    {
        return $this->op('service-unlink', $this->employee->id, $this->hr->id);
    }

    private function linkNow(User $user): void
    {
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $user->id, $this->hr));
    }

    private function decemberPayingANewChange(): PayrollRun
    {
        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $this->employee->id)->firstOrFail(), $this->setup['structure'], Carbon::parse('2026-12-01'),
            [new FixedComponentValueInput($this->setup['line'], '60000.00')], $this->x, $this->freshMfaProofFor($this->x),
        ));

        return $this->calculatedRun('2026-12-01');
    }

    private function runStatus(PayrollRun $run): string
    {
        return (string) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $run->id)->value('status'));
    }

    /** @return list<object> */
    private function links(): array
    {
        return $this->inSchool($this->school, fn () => DB::table('employee_account_links')->where('employee_id', $this->employee->id)->orderBy('linked_at')->get()->all());
    }

    private function currentLink(): ?string
    {
        return $this->inSchool($this->school, fn () => $this->employee->fresh()->user_id);
    }

    private function postedThenReversed(): PayrollReversalReconciliation
    {
        $this->postRun($this->november);
        $this->reverse($this->october);

        return $this->inSchool($this->school, fn () => PayrollReversalReconciliation::query()->where('payroll_run_id', $this->november->id)->firstOrFail());
    }

    private function version(PayrollReversalReconciliation $o): string
    {
        return $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationService::class)->version($o));
    }

    private function reviews(): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_reversal_reconciliation_reviews')->count());
    }

    private function attestations(PayrollRun $run): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $run->id)->count());
    }

    // Link against link.

    #[Test]
    public function a_link_held_open_is_ordered_before_a_waiting_unlink_and_both_are_recorded(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('service-link', $this->employee->id, $this->reviewer->id, $this->hr->id), $this->unlinkOp());

        $this->assertSame(['ok', 'ok'], [$holder, $contender]);
        $this->assertNull($this->currentLink());
        $links = $this->links();
        $this->assertCount(1, $links);
        $this->assertNotNull($links[0]->ended_at);
    }

    #[Test]
    public function a_relink_waiting_on_a_link_is_refused_and_leaves_no_false_history(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('service-link', $this->employee->id, $this->reviewer->id, $this->hr->id),
            $this->op('service-link', $this->employee->id, $this->checker->id, $this->hr->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame($this->reviewer->id, $this->currentLink());
        $this->assertCount(1, $this->links(), 'one row, the one that happened');
    }

    #[Test]
    public function a_duplicate_of_the_same_link_is_refused_and_records_once(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('service-link', $this->employee->id, $this->reviewer->id, $this->hr->id),
            $this->op('service-link', $this->employee->id, $this->reviewer->id, $this->hr->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertCount(1, $this->links());
    }

    // Link against approval.

    #[Test]
    public function an_unlink_held_open_never_lets_a_waiting_approval_through(): void
    {
        $this->linkNow($this->reviewer);
        $december = $this->decemberPayingANewChange();

        [$holder, $contender] = $this->raceWithHeldHolder($this->unlinkOp(), $this->approveCommand($december, $this->reviewer));

        $this->assertSame('ok', $holder);
        $this->assertSame(self::REFUSED, $contender, 'it waited for the unlink, and the unlink changed nothing it reads');
        $this->assertSame('calculated', $this->runStatus($december));
        $this->assertNull($this->currentLink());
    }

    #[Test]
    public function an_independent_approval_held_open_is_ordered_before_a_waiting_unlink(): void
    {
        $this->linkNow($this->reviewer);
        $december = $this->decemberPayingANewChange();

        [$holder, $contender] = $this->raceWithHeldHolder($this->approveCommand($december, $this->checker), $this->unlinkOp());

        $this->assertSame(['approved', 'ok'], [$holder, $contender]);
        $this->assertSame('approved', $this->runStatus($december));
        $this->assertNull($this->currentLink());
    }

    // Link against attestation.

    #[Test]
    public function an_unlink_held_open_never_lets_a_waiting_attestation_through(): void
    {
        $this->reverse($this->october);
        $this->linkNow($this->reviewer);

        [$holder, $contender] = $this->raceWithHeldHolder($this->unlinkOp(), $this->attestCommand($this->november, $this->reviewer));

        $this->assertSame('ok', $holder);
        $this->assertSame(self::REFUSED, $contender);
        $this->assertSame('approved', $this->runStatus($this->november));
        $this->assertSame(0, $this->attestations($this->november));
    }

    #[Test]
    public function an_independent_attestation_held_open_is_ordered_before_a_waiting_unlink(): void
    {
        $this->reverse($this->october);
        $this->linkNow($this->reviewer);

        [$holder, $contender] = $this->raceWithHeldHolder($this->attestCommand($this->november, $this->finance), $this->unlinkOp());

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertSame('ok', $contender);
        $this->assertSame(1, $this->attestations($this->november));
    }

    // Link against reconciliation.

    #[Test]
    public function an_unlink_held_open_never_lets_a_waiting_review_through(): void
    {
        $this->linkNow($this->reviewer);
        $o = $this->postedThenReversed();

        [$holder, $contender] = $this->raceWithHeldHolder($this->unlinkOp(), $this->op('review', $o->id, $this->reviewer->id, $this->version($o)));

        $this->assertSame('ok', $holder);
        $this->assertSame(self::REFUSED, $contender);
        $this->assertSame(0, $this->reviews());
    }

    #[Test]
    public function an_independent_review_held_open_is_ordered_before_a_waiting_unlink(): void
    {
        $this->linkNow($this->reviewer);
        $o = $this->postedThenReversed();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('review', $o->id, $this->checker->id, $this->version($o)), $this->unlinkOp());

        $this->assertSame(['ok', 'ok'], [$holder, $contender]);
        $this->assertSame(1, $this->reviews());
    }

    // Link against calculation: the race the C6 report left untested.

    /** @return array{sources: int, version: string} */
    private function calculationEvidence(PayrollRun $run): array
    {
        return $this->inSchool($this->school, function () use ($run): array {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();

            return [
                'sources' => DB::table('payroll_run_calculation_sources')->where('payroll_run_id', $run->id)->where('payroll_run_calculation_id', $runs->latestCalculationId($fresh))->count(),
                'version' => (string) $runs->latestAttributionVersion($fresh),
            ];
        });
    }

    #[Test]
    public function a_link_held_open_is_ordered_before_a_waiting_calculation_and_changes_nothing_it_records(): void
    {
        $december = $this->decemberPayingANewChange();
        $before = $this->calculationEvidence($december);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('service-link', $this->employee->id, $this->reviewer->id, $this->hr->id), $this->calculateCommand($december));

        $this->assertSame('ok', $holder);
        $this->assertSame('calculated', $contender);
        $after = $this->calculationEvidence($december);
        $this->assertSame($before['sources'], $after['sources'], 'provenance reads compensation, never identity');
        $this->assertSame($before['version'], $after['version'], 'and the approval evidence does not move');
        $this->assertSame(self::REFUSED, $this->outcomeOfApproving($december, $this->reviewer), 'the attribution is read at the decision');
    }

    #[Test]
    public function a_calculation_held_open_is_ordered_before_a_waiting_link(): void
    {
        $december = $this->decemberPayingANewChange();

        [$holder, $contender] = $this->raceWithHeldHolder($this->calculateCommand($december), $this->op('service-link', $this->employee->id, $this->reviewer->id, $this->hr->id));

        $this->assertSame(['calculated', 'ok'], [$holder, $contender]);
        $this->assertSame(self::REFUSED, $this->outcomeOfApproving($december, $this->reviewer));
    }

    #[Test]
    public function an_unlink_racing_a_calculation_leaves_the_attribution_intact(): void
    {
        $this->linkNow($this->reviewer);
        $december = $this->decemberPayingANewChange();

        [$holder, $contender] = $this->raceWithHeldHolder($this->unlinkOp(), $this->calculateCommand($december));

        $this->assertSame(['ok', 'calculated'], [$holder, $contender]);
        $this->assertSame(self::REFUSED, $this->outcomeOfApproving($december, $this->reviewer));
    }

    private function outcomeOfApproving(PayrollRun $run, User $as): string
    {
        try {
            $this->approve($run, $as);

            return 'approved';
        } catch (\Throwable $e) {
            return 'rejected:'.$e::class;
        }
    }

    // A correction against a reader.

    #[Test]
    public function a_reader_sees_a_correction_only_once_it_commits(): void
    {
        $this->linkNow($this->reviewer);
        $december = $this->decemberPayingANewChange();
        $link = $this->links()[0];
        $during = null;

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('correct', $this->employee->id, $link->id, $this->hr->id),
            $this->approveCommand($december, $this->reviewer),
            function () use ($link, &$during): void {
                $during = DB::connection('pgsql_admin')->table('employee_account_links')->where('id', $link->id)->value('voided_at');
            },
        );

        $this->assertNull($during, 'no half-corrected state is visible');
        $this->assertSame(['ok', 'approved'], [$holder, $contender], 'it waited for the correction, and acted on its result');
        $this->assertSame('wrong_person', $this->links()[0]->void_reason);
        $this->assertNull($this->currentLink());
    }

    #[Test]
    public function a_decision_held_open_is_ordered_before_a_waiting_correction_and_keeps_what_it_read(): void
    {
        $this->linkNow($this->reviewer);
        $december = $this->decemberPayingANewChange();
        $link = $this->links()[0];

        [$holder, $contender] = $this->raceWithHeldHolder($this->approveCommand($december, $this->checker), $this->op('correct', $this->employee->id, $link->id, $this->hr->id));

        $this->assertSame(['approved', 'ok'], [$holder, $contender]);
        $this->assertSame('approved', $this->runStatus($december));
        $this->assertSame('wrong_person', $this->links()[0]->void_reason);
    }

    #[Test]
    public function two_corrections_of_one_link_record_once(): void
    {
        $this->linkNow($this->reviewer);
        $link = $this->links()[0];

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('correct', $this->employee->id, $link->id, $this->hr->id),
            $this->op('correct', $this->employee->id, $link->id, $this->hr->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:App\\Domain\\HR\\Application\\Exceptions\\EmployeeAccountLinkAlreadyCorrectedException', $contender);
        $this->assertSame(1, (int) $this->inSchool($this->school, fn () => DB::table('school_audit_events')->where('event_type', 'employee.user_link_corrected')->count()));
    }
}
