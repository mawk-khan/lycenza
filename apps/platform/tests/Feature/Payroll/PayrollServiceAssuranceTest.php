<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C1 -- S-5 ("Fresh-MFA proof is optional at the Payroll service
 * layer") and S-13 ("PayrollPostingService::post() has no capability check;
 * the demo posts as a user without the key"), proven at the trusted CORE
 * services themselves: no route, no controller, no Administration wrapper.
 */
class PayrollServiceAssuranceTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    private School $school;

    /** @var array<string, mixed> */
    private array $setup;

    private EmploymentRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2015-07-01 06:00:00'));
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $employee = $this->paidEmployee($this->school, $this->setup);
        $this->record = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail());
    }

    private function calculated(): PayrollRun
    {
        $preparer = $this->createUser();

        return $this->inSchool($this->school, function () use ($preparer): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse('2015-07-01'), null, $preparer), $preparer);
            $run = app(PayrollRunService::class)->createRun($period, $preparer);
            app(PayrollRunService::class)->calculate($run, $preparer);

            return $run->fresh();
        });
    }

    private function approved(): PayrollRun
    {
        $run = $this->calculated();
        $checker = $this->createUser();

        return $this->inSchool($this->school, fn () => app(PayrollRunService::class)->approve($run, $checker, $this->freshMfaProofFor($checker), app(PayrollRunService::class)->latestCalculationId($run), app(PayrollRunService::class)->latestAttributionVersion($run)));
    }

    private function runStatus(PayrollRun $run): string
    {
        return $this->inSchool($this->school, fn () => (string) DB::table('payroll_runs')->where('id', $run->id)->value('status'));
    }

    #[Test]
    public function s_5_the_core_approval_refuses_a_proof_that_belongs_to_someone_else(): void
    {
        $run = $this->calculated();
        [$checker, $someoneElse] = [$this->createUser(), $this->createUser()];

        $this->expectException(LogicException::class);
        $this->inSchool($this->school, fn () => app(PayrollRunService::class)->approve($run, $checker, $this->freshMfaProofFor($someoneElse), app(PayrollRunService::class)->latestCalculationId($run), app(PayrollRunService::class)->latestAttributionVersion($run)));
    }

    #[Test]
    public function s_5_a_compensation_change_refuses_a_proof_that_belongs_to_someone_else(): void
    {
        [$author, $someoneElse] = [$this->createUser(), $this->createUser()];
        try {
            $this->inSchool($this->school, fn () => app(CompensationService::class)->assign($this->school, $this->record->fresh(), $this->setup['structure'],
                Carbon::parse('2015-08-01'), [new FixedComponentValueInput($this->setup['line'], '60000.00')], $author, $this->freshMfaProofFor($someoneElse)));
            $this->fail('a pay change was recorded on another person\'s fresh-MFA proof');
        } catch (LogicException) {
            $this->assertSame(1, $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $this->record->id)->count()));
        }
    }

    #[Test]
    public function s_5_posting_and_reversal_refuse_a_proof_that_belongs_to_someone_else(): void
    {
        $run = $this->approved();
        $poster = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse']);
        try {
            $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $poster, $this->freshMfaProofFor($this->createUser())));
            $this->fail('posted on another person\'s proof');
        } catch (LogicException) {
            $this->assertSame('approved', $this->runStatus($run));
        }
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $poster, $this->freshMfaProofFor($poster)));
        $this->expectException(LogicException::class);
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $poster, $this->freshMfaProofFor($this->createUser())));
    }

    #[Test]
    public function s_13_the_posting_core_refuses_an_actor_without_payroll_runs_post(): void
    {
        $run = $this->approved();
        $noKey = $this->createUserWithCapabilities($this->school, ['payroll.runs.view']);
        try {
            $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $noKey, $this->freshMfaProofFor($noKey)));
            $this->fail('the posting core posted for an actor without payroll.runs.post');
        } catch (AuthorizationException) {
            $this->assertSame('approved', $this->runStatus($run));
            $this->assertSame(0, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $run->id)->count()));
        }
    }

    #[Test]
    public function s_13_the_posting_core_refuses_a_key_held_only_in_another_school(): void
    {
        $run = $this->approved();
        $elsewhere = $this->createUserWithCapabilities($this->createSchool(), ['payroll.runs.post']);
        $this->expectException(AuthorizationException::class);
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $elsewhere, $this->freshMfaProofFor($elsewhere)));
    }

    #[Test]
    public function s_13_reversal_needs_the_current_reverse_key_not_the_post_key(): void
    {
        $run = $this->approved();
        $poster = $this->createUserWithCapabilities($this->school, ['payroll.runs.post']);
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $poster, $this->freshMfaProofFor($poster)));
        $this->assertSame('posted', $this->runStatus($run));

        try {
            $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $poster, $this->freshMfaProofFor($poster), 'wrong'));
            $this->fail('reversed without payroll.runs.reverse');
        } catch (AuthorizationException) {
            $this->assertSame(1, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $run->id)->count()));
        }
        $reverser = $this->createUserWithCapabilities($this->school, ['payroll.runs.reverse']);
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $reverser, $this->freshMfaProofFor($reverser), 'wrong'));
        $this->assertSame(2, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $run->id)->count()));
    }

    #[Test]
    public function s_5_every_payroll_decision_core_declares_a_required_proof(): void
    {
        foreach ([
            [PayrollRunService::class, 'approve'], [PayrollPostingService::class, 'post'],
            [PayrollPostingService::class, 'reverse'], [CompensationService::class, 'assign'],
        ] as [$class, $method]) {
            $param = collect((new \ReflectionMethod($class, $method))->getParameters())->first(fn (\ReflectionParameter $p) => $p->getName() === 'mfa');
            $this->assertNotNull($param, "{$class}::{$method} takes the proof");
            $this->assertSame('App\\Support\\Auth\\Mfa\\FreshMfaProof', (string) $param->getType(), "{$class}::{$method}: a proof, never nullable");
        }
    }
}
