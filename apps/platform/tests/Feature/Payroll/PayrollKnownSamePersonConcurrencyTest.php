<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
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
 * HR.R1-C34 (WO-16), on COMMITTED data, in genuinely separate OS processes, the holder held
 * open and the contender observed blocked on a lock: a link that makes the approver known to be
 * the preparer, against the approval.
 *
 * The order is the one PayrollAccountLinkConcurrencyTest proves for the C7 rules: a link writer
 * takes the compensation lock EXCLUSIVELY (trg_employees_user_link_payroll_lock), an approval takes
 * it SHARED under the run row before it reads the participants. So the decision is evaluated on one
 * consistent history: the link first -> refused; the approval first -> it stands, and the later link
 * never re-reads it (C33: never retroactive).
 */
class PayrollKnownSamePersonConcurrencyTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap, ProvidesSensitiveActionMfa;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $preparer;

    private User $alt;

    private User $hr;

    private Employee $own;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $setup = $this->payrollSetup($this->school);
        $this->paidEmployee($this->school, $setup);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $this->alt = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->hr = $this->fullHrActor($this->school);
        // The preparer's own Employee (never paid): linked to the preparer, then unlinked. Linking it to `alt`
        // is what makes the two accounts one person.
        $this->own = $this->createEmployee($this->school, ['user_id' => null]);
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($this->own->fresh(), $this->preparer->id, $this->hr));
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->unlinkUser($this->own->fresh(), $this->hr));
        $this->run = $this->inSchool($this->school, function (): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse('2026-10-01'), null, $this->preparer), $this->preparer);
            $run = app(PayrollRunService::class)->createRun($period, $this->preparer);
            app(PayrollRunService::class)->calculate($run, $this->preparer);

            return $run->fresh();
        });
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
    private function linkAlt(): array
    {
        return ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', 'service-link', $this->school->id, $this->own->id, $this->alt->id, $this->hr->id];
    }

    /** @return list<string> */
    private function approveAsAlt(): array
    {
        return ['php', __DIR__.'/../../Support/approve-payroll-run.php', $this->school->id, $this->run->id, $this->alt->id];
    }

    private function runStatus(): string
    {
        return (string) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $this->run->id)->value('status'));
    }

    private function knownSame(): bool
    {
        return $this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePerson($this->school->id, $this->preparer->id, $this->alt->id));
    }

    #[Test]
    public function a_link_held_open_is_seen_by_the_waiting_approval_which_is_refused(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->linkAlt(), $this->approveAsAlt());

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\KnownSamePersonDecisionException', $contender, 'it waited for the link, then read it');
        $this->assertSame('calculated', $this->runStatus());
        $this->assertTrue($this->knownSame());
    }

    #[Test]
    public function an_approval_held_open_is_ordered_before_the_waiting_link_and_stands(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->approveAsAlt(), $this->linkAlt());

        $this->assertSame(['approved', 'ok'], [$holder, $contender]);
        $this->assertSame('approved', $this->runStatus(), 'decided on the history as it stood; never re-read (C33)');
        $this->assertTrue($this->knownSame(), 'the later link is recorded');
    }
}
