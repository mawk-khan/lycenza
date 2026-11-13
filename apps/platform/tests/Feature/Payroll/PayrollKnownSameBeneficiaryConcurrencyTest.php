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
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-17 (C34-N1, C34-N2), on COMMITTED data, in genuinely separate OS processes, the
 * holder held open and the contender observed blocked on a lock: a link that makes the decider D
 * known to be the same person as A, the account of the paid Employee E, against each Payroll
 * decision the self-beneficiary rule protects -- approval, posting attestation and reconciliation
 * review.
 *
 * A link writer takes the compensation lock EXCLUSIVELY (trg_employees_user_link_payroll_lock); each
 * decision takes it SHARED under its run row before it reads the beneficiaries. So each decision is
 * judged on one consistent history: the link first -> refused; the decision first -> it stands,
 * never re-read (C33).
 */
class PayrollKnownSameBeneficiaryConcurrencyTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap, ProvidesSensitiveActionMfa;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const REFUSED = 'rejected:App\\Domain\\Payroll\\Application\\Exceptions\\SelfBeneficiaryConflictException';

    private ?School $school = null;

    /** @var array<string, mixed> */
    private array $setup;

    private Employee $employee;

    private Employee $duplicate;

    private User $preparer;

    private User $checker;

    private User $x;

    private User $finance;

    private User $d;

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
        $this->d = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view', 'payroll.runs.post']);
        $a = $this->createUserWithCapabilities($this->school, ['payroll.runs.view']);
        $this->hr = $this->fullHrActor($this->school);

        // A was linked to the duplicate Employee E', then to the paid Employee E. Linking E' to D makes D known to be A.
        $this->duplicate = $this->createEmployee($this->school, ['user_id' => null]);
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($this->duplicate->fresh(), $a->id, $this->hr));
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->unlinkUser($this->duplicate->fresh(), $this->hr));
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $a->id, $this->hr));

        $this->assign('2026-10-01', '55000.00');
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

    private function assign(string $from, string $amount): void
    {
        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $this->employee->id)->firstOrFail(), $this->setup['structure'], Carbon::parse($from),
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
    private function linkD(): array
    {
        return ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', 'service-link', $this->school->id, $this->duplicate->id, $this->d->id, $this->hr->id];
    }

    /** @return list<string> */
    private function approveAsD(PayrollRun $run): array
    {
        return ['php', __DIR__.'/../../Support/approve-payroll-run.php', $this->school->id, $run->id, $this->d->id];
    }

    /** @return list<string> */
    private function attestAsD(PayrollRun $run): array
    {
        $version = $this->inSchool($this->school, fn () => (string) app(PayrollPostingIndependence::class)->version($run->fresh()));

        return ['php', __DIR__.'/../../Support/post-payroll-run.php', $this->school->id, $run->id, $this->d->id, $version];
    }

    /** @return list<string> */
    private function reviewAsD(PayrollReversalReconciliation $o): array
    {
        $version = $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationService::class)->version($o));

        return ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', 'review', $this->school->id, $o->id, $this->d->id, $version];
    }

    private function rowCount(string $table, string $column, string $id): int
    {
        return $this->inSchool($this->school, fn () => DB::table($table)->where($column, $id)->count());
    }

    private function runStatus(PayrollRun $run): string
    {
        return (string) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $run->id)->value('status'));
    }

    private function obligation(): PayrollReversalReconciliation
    {
        $this->postRun($this->november);
        $this->reverse($this->october);

        return $this->inSchool($this->school, fn () => PayrollReversalReconciliation::query()->where('payroll_run_id', $this->november->id)->firstOrFail());
    }

    #[Test]
    public function a_link_held_open_is_seen_by_a_waiting_approval_which_is_refused(): void
    {
        $this->assign('2026-12-01', '60000.00');
        $december = $this->calculatedRun('2026-12-01');

        [$holder, $contender] = $this->raceWithHeldHolder($this->linkD(), $this->approveAsD($december));

        $this->assertSame(['ok', self::REFUSED], [$holder, $contender]);
        $this->assertSame('calculated', $this->runStatus($december));
    }

    #[Test]
    public function an_approval_held_open_is_ordered_before_a_waiting_link_and_stands(): void
    {
        $this->assign('2026-12-01', '60000.00');
        $december = $this->calculatedRun('2026-12-01');

        [$holder, $contender] = $this->raceWithHeldHolder($this->approveAsD($december), $this->linkD());

        $this->assertSame(['approved', 'ok'], [$holder, $contender]);
        $this->assertSame('approved', $this->runStatus($december), 'never re-read');
    }

    #[Test]
    public function a_link_held_open_is_seen_by_a_waiting_attestation_which_is_refused(): void
    {
        $this->reverse($this->october);

        [$holder, $contender] = $this->raceWithHeldHolder($this->linkD(), $this->attestAsD($this->november));

        $this->assertSame(['ok', self::REFUSED], [$holder, $contender]);
        $this->assertSame('approved', $this->runStatus($this->november));
        $this->assertSame(0, $this->rowCount('payroll_posting_attestations', 'payroll_run_id', $this->november->id));
    }

    #[Test]
    public function an_attestation_held_open_is_ordered_before_a_waiting_link_and_stands(): void
    {
        $this->reverse($this->october);

        [$holder, $contender] = $this->raceWithHeldHolder($this->attestAsD($this->november), $this->linkD());

        $this->assertStringStartsWith('posted', $holder);
        $this->assertSame('ok', $contender);
        $this->assertSame(1, $this->rowCount('payroll_posting_attestations', 'payroll_run_id', $this->november->id));
    }

    #[Test]
    public function a_link_held_open_is_seen_by_a_waiting_review_which_is_refused(): void
    {
        $o = $this->obligation();

        [$holder, $contender] = $this->raceWithHeldHolder($this->linkD(), $this->reviewAsD($o));

        $this->assertSame(['ok', self::REFUSED], [$holder, $contender]);
        $this->assertSame(0, $this->rowCount('payroll_reversal_reconciliation_reviews', 'reconciliation_id', $o->id));
    }

    #[Test]
    public function a_review_held_open_is_ordered_before_a_waiting_link_and_stands(): void
    {
        $o = $this->obligation();

        [$holder, $contender] = $this->raceWithHeldHolder($this->reviewAsD($o), $this->linkD());

        $this->assertSame(['ok', 'ok'], [$holder, $contender]);
        $this->assertSame(1, $this->rowCount('payroll_reversal_reconciliation_reviews', 'reconciliation_id', $o->id));
    }
}
