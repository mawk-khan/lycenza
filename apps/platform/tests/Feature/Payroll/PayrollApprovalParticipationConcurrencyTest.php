<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * ORG.8B (ADR 0076 §60): committed, real PostgreSQL, two OS processes; the
 * contender is observed BLOCKED on the run row the holder locked
 * (calculate, an override and approve all take it):
 *
 *   A  B recalculates (held)        -> B's approval waits, then is refused (B participated)
 *   B  D approves (held)            -> B's recalculation waits, then the run is not editable
 *   C  C records an override (held) -> D's approval waits, then needs a recalculation (stale)
 *   D  D approves (held)            -> C's override waits, then the run is not editable
 *   E  two approvals                -> one approval
 *   F  B recalculates (held)        -> B's approval through the authorized entry (run lock,
 *                                      then current authority) waits, then is refused
 *
 * Never: an approval of results a concurrent calculation or override changes.
 */
class PayrollApprovalParticipationConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->purgeSchoolsAsAdmin([$this->school->id]);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $this->school = $this->createSchool();
        $setup = $this->payrollSetup($this->school);
        $employee = $this->paidEmployee($this->school, $setup);
        $creator = $this->createUser();

        $this->travelTo(Carbon::parse('2015-08-10 06:00:00'));
        $run = $this->inSchool($this->school, function () use ($creator): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse('2015-08-01'), null, $creator), $creator);
            $run = app(PayrollRunService::class)->createRun($period, $creator);
            app(PayrollRunService::class)->calculate($run, $creator);

            return $run->fresh();
        });
        $this->travelBack();

        return [
            'run' => $run,
            'record' => $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail()->id),
            'basic' => $this->inSchool($this->school, fn () => SalaryComponent::query()->where('code', 'BASIC')->firstOrFail()->id),
        ];
    }

    /** @return array<int, string> */
    private function op(array $w, string $operation, User $actor, string ...$extra): array
    {
        return ['php', __DIR__.'/../../Support/payroll-approval-op.php', $operation, $this->school->id, $w['run']->id, $actor->id, ...$extra];
    }

    private function runStatus(array $w): string
    {
        return $this->inSchool($this->school, fn () => $w['run']->fresh()->status);
    }

    #[Test]
    public function a_recalculator_waiting_to_approve_is_refused_once_their_recalculation_commits(): void
    {
        $w = $this->world();
        $b = $this->createUser();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'calculate', $b), $this->op($w, 'approve', $b));

        $this->assertSame('calculated', $holder);
        $this->assertSame('rejected:SelfApprovalNotAllowedException', $contender);
        $this->assertSame('calculated', $this->runStatus($w));
    }

    #[Test]
    public function a_recalculation_waiting_on_an_approval_finds_the_run_decided(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'approve', $this->createUser()), $this->op($w, 'calculate', $this->createUser()));

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:RunNotEditableException', $contender);
    }

    #[Test]
    public function an_approval_waiting_on_an_override_needs_a_recalculation(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op($w, 'override', $this->createUser(), $w['record'], $w['basic'], '41000.00'),
            $this->op($w, 'approve', $this->createUser()),
        );

        $this->assertSame('overridden', $holder);
        $this->assertSame('rejected:RunRecalculationRequiredException', $contender, 'never approves results that ignore the override');
        $this->assertSame('calculated', $this->runStatus($w));
    }

    #[Test]
    public function an_override_waiting_on_an_approval_finds_the_run_decided(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op($w, 'approve', $this->createUser()),
            $this->op($w, 'override', $this->createUser(), $w['record'], $w['basic'], '41000.00'),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:RunNotEditableException', $contender);
    }

    #[Test]
    public function two_approvals_make_one(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'approve', $this->createUser()), $this->op($w, 'approve', $this->createUser()));

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:ConcurrentRunApprovalConflictException', $contender);
    }

    #[Test]
    public function the_authorized_entry_waits_on_the_run_and_still_refuses_the_recalculator(): void
    {
        $w = $this->world();
        $b = $this->createUserWithCapabilities($this->school, ['payroll.runs.view', 'payroll.runs.prepare', 'payroll.runs.approve']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'calculate', $b), $this->op($w, 'approve-entry', $b));

        $this->assertSame('calculated', $holder);
        $this->assertSame('rejected:SelfApprovalNotAllowedException', $contender);
    }
}
