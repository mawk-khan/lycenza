<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
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
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO17-N1 (Option A), on COMMITTED data, in genuinely separate OS processes, holder held open and
 * contender observed blocked: November was approved by D, an account known to be the paid Employee's; October,
 * which paid the raise first, is reversed while November is posted.
 *
 * A reversal takes the compensation lock EXCLUSIVELY after its run row; a posting takes it SHARED after its
 * own. So: the reversal first -> November now pays the raise first, its approver is the Employee, and the
 * waiting posting without an attestation is refused; the posting first -> it stands, and the waiting
 * reversal opens the reconciliation obligation for it (rule 144). Nothing is rewritten in either order.
 */
class PayrollKnownSameIndependenceConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap, ProvidesSensitiveActionMfa;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $finance;

    private PayrollRun $october;

    private PayrollRun $november;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $setup = $this->payrollSetup($this->school);
        $employee = $this->paidEmployee($this->school, $setup);
        $preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $u = $this->createUserWithCapabilities($this->school, ['payroll.runs.view']);
        $d = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $hr = $this->fullHrActor($this->school);

        // U was linked to the duplicate E', then to the paid Employee E; D is linked to E'. D is known to be E.
        $duplicate = $this->createEmployee($this->school, ['user_id' => null]);
        $links = app(EmployeeService::class);
        $this->inSchool($this->school, fn () => $links->linkUser($duplicate->fresh(), $u->id, $hr));
        $this->inSchool($this->school, fn () => $links->unlinkUser($duplicate->fresh(), $hr));
        $this->inSchool($this->school, fn () => $links->linkUser($employee->fresh(), $u->id, $hr));
        $this->inSchool($this->school, fn () => $links->linkUser(Employee::query()->findOrFail($duplicate->id), $d->id, $hr));

        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail(), $setup['structure'], Carbon::parse('2026-10-01'),
            [new FixedComponentValueInput($setup['line'], '55000.00')], $x, $this->freshMfaProofFor($x),
        ));
        $run = function (string $month) use ($preparer): PayrollRun {
            return $this->inSchool($this->school, function () use ($month, $preparer): PayrollRun {
                $periods = app(PayrollPeriodService::class);
                $period = $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $preparer), $preparer);
                $r = app(PayrollRunService::class)->createRun($period, $preparer);
                app(PayrollRunService::class)->calculate($r, $preparer);

                return $r->fresh();
            });
        };
        $approve = function (PayrollRun $r, User $as): void {
            $this->inSchool($this->school, function () use ($r, $as): void {
                $runs = app(PayrollRunService::class);
                $fresh = $r->fresh();
                $runs->approve($fresh, $as, $this->freshMfaProofFor($as), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
            });
        };
        $this->october = $run('2026-10-01');
        $approve($this->october, $checker);
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($this->october->fresh(), $this->finance, $this->freshMfaProofFor($this->finance)));
        $this->november = $run('2026-11-01');
        $approve($this->november, $d); // allowed: October paid the raise first
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

    /** @return list<string> */
    private function reverseOctober(): array
    {
        return ['php', __DIR__.'/../../Support/reverse-payroll-run.php', $this->school->id, $this->october->id, $this->finance->id];
    }

    /** @return list<string> */
    private function postNovember(): array
    {
        return ['php', __DIR__.'/../../Support/post-payroll-run.php', $this->school->id, $this->november->id, $this->finance->id];
    }

    private function novemberRow(): object
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $this->november->id)->first(['status', 'approved_by_user_id', 'approved_at']));
    }

    private function obligations(): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_reversal_reconciliations')->where('payroll_run_id', $this->november->id)->count());
    }

    #[Test]
    public function a_reversal_held_open_makes_the_waiting_posting_of_the_alias_approved_run_need_an_attestation(): void
    {
        $before = $this->novemberRow();

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseOctober(), $this->postNovember());

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\PostingAttestationRequiredException', $contender);
        $this->assertEquals($before, $this->novemberRow(), 'still approved, approval untouched');
    }

    #[Test]
    public function a_posting_held_open_is_ordered_first_and_the_waiting_reversal_opens_its_obligation(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->postNovember(), $this->reverseOctober());

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertStringStartsWith('reversed:', $contender);
        $this->assertSame('posted', $this->novemberRow()->status, 'the posting stands');
        $this->assertSame(1, $this->obligations(), 'the reversal found the alias-approved run (rule 144)');
    }
}
