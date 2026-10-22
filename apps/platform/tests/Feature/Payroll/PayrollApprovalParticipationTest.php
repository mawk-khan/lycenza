<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\RunNotEditableException;
use App\Domain\Payroll\Application\Exceptions\RunRecalculationRequiredException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * ORG.8B (ADR 0076 §60, closes ORG.8 G-2): nobody who materially prepared a
 * payroll run approves it -- its creator, anyone who calculated or
 * recalculated it, anyone who recorded an override or delta on it -- per
 * run, monotonically, through the shared MakerCheckerPolicy. Before 8B only
 * the creator was refused: a recalculator or an override author approved.
 * An approval also covers calculated results only: an adjustment recorded
 * after the last calculation, or a run with no recorded calculation, needs a
 * recalculation first.
 */
class PayrollApprovalParticipationTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    /** @return array<string, mixed> a calculated regular run created and calculated by its creator */
    private function world(): array
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $employee = $this->paidEmployee($school, $setup);
        $creator = $this->createUser();

        $this->travelTo(Carbon::parse('2015-08-10 06:00:00'));
        $run = $this->inSchool($school, function () use ($school, $creator): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse('2015-08-01'), null, $creator), $creator);
            $run = $this->runs()->createRun($period, $creator);
            $this->runs()->calculate($run, $creator);

            return $run->fresh();
        });
        $this->travelBack();

        return [
            'school' => $school, 'run' => $run, 'creator' => $creator,
            'record' => $this->inSchool($school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail()),
            'basic' => $this->inSchool($school, fn () => SalaryComponent::query()->where('code', 'BASIC')->firstOrFail()->id),
        ];
    }

    private function runs(): PayrollRunService
    {
        return app(PayrollRunService::class);
    }

    private function fresh(array $w): PayrollRun
    {
        return $this->inSchool($w['school'], fn () => $w['run']->fresh());
    }

    private function override(array $w, User $actor, string $amount = '41000.00'): void
    {
        $this->inSchool($w['school'], fn () => $this->runs()->recordManualOverride($this->fresh($w), $w['record'], [$w['basic'] => $amount], 'Joined mid-month', $actor));
    }

    private function recalculate(array $w, User $actor): void
    {
        $this->inSchool($w['school'], fn () => $this->runs()->calculate($this->fresh($w), $actor));
    }

    private function approve(array $w, User $approver): PayrollRun
    {
        return $this->inSchool($w['school'], fn () => $this->runs()->approve($this->fresh($w), $approver));
    }

    private function assertRefused(array $w, User $approver, string $exception): void
    {
        try {
            $this->approve($w, $approver);
            $this->fail("{$exception} expected");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }
        $this->assertSame('calculated', $this->fresh($w)->status, 'nothing approved');
    }

    #[Test]
    public function the_creator_never_approves(): void
    {
        $w = $this->world();
        $this->assertRefused($w, $w['creator'], SelfApprovalNotAllowedException::class);
    }

    #[Test]
    public function a_recalculator_never_approves_even_after_someone_else_recalculates_again(): void
    {
        $w = $this->world();
        $recalculator = $this->createUser();
        $this->recalculate($w, $recalculator);
        $this->assertRefused($w, $recalculator, SelfApprovalNotAllowedException::class);

        $this->recalculate($w, $this->createUser());
        $this->assertRefused($w, $recalculator, SelfApprovalNotAllowedException::class);

        $this->assertSame('approved', $this->approve($w, $this->createUser())->status, 'a non-participant approves');
    }

    #[Test]
    public function an_override_author_never_approves_and_stale_results_need_a_recalculation(): void
    {
        $w = $this->world();
        $author = $this->createUser();
        $this->override($w, $author);
        $this->assertRefused($w, $author, SelfApprovalNotAllowedException::class);

        $outsider = $this->createUser();
        $this->assertRefused($w, $outsider, RunRecalculationRequiredException::class);

        $this->recalculate($w, $this->createUser());
        $this->override($w, $this->createUser(), '42000.00');
        $this->assertRefused($w, $outsider, RunRecalculationRequiredException::class);

        $this->recalculate($w, $this->createUser());
        $this->assertRefused($w, $author, SelfApprovalNotAllowedException::class);
        $this->assertSame('approved', $this->approve($w, $outsider)->status);
    }

    #[Test]
    public function participation_is_per_run(): void
    {
        $w = $this->world();
        $this->assertSame([$w['creator']->id], $this->runs()->participants($this->fresh($w))->all()['creator']);

        $this->travelTo(Carbon::parse('2015-09-10 06:00:00'));
        $other = $this->inSchool($w['school'], function () use ($w): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($w['school'], Carbon::parse('2015-09-01'), null, $w['creator']), $w['creator']);
            $run = $this->runs()->createRun($period, $this->createUser());
            $this->runs()->calculate($run, $this->createUser());

            return $run->fresh();
        });
        $this->travelBack();

        $this->assertSame('approved', $this->inSchool($w['school'], fn () => $this->runs()->approve($other, $w['creator']))->status, 'the creator of run 1 approves run 2');
    }

    #[Test]
    public function a_run_with_no_recorded_calculation_fails_closed(): void
    {
        $w = $this->world();
        $legacy = $this->inSchool($w['school'], function () use ($w): PayrollRun {
            $this->travelTo(Carbon::parse('2015-10-10 06:00:00'));
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($w['school'], Carbon::parse('2015-10-01'), null, $w['creator']), $w['creator']);
            $run = $this->runs()->createRun($period, $w['creator']);
            $this->travelBack();

            return $run;
        });
        // A run calculated before ORG.8B: calculated, but nobody's calculation is recorded.
        $this->inSchool($w['school'], fn () => DB::table('payroll_runs')->where('id', $legacy->id)->update(['status' => 'calculated']));

        $this->expectException(RunRecalculationRequiredException::class);
        $this->inSchool($w['school'], fn () => $this->runs()->approve($legacy->fresh(), $this->createUser()));
    }

    #[Test]
    public function a_decided_run_takes_no_more_preparation(): void
    {
        $w = $this->world();
        $this->approve($w, $this->createUser());

        $this->expectException(RunNotEditableException::class);
        $this->override($w, $this->createUser());
    }

    #[Test]
    public function the_authorized_entry_reads_current_authority_and_records_provenance(): void
    {
        $w = $this->world();
        $approver = $this->createUserWithCapabilities($w['school'], ['payroll.runs.view', 'payroll.runs.approve']);
        $revoked = $this->createUserWithCapabilities($w['school'], ['payroll.runs.view', 'payroll.runs.approve']);
        $this->assertTrue(Gate::forUser($revoked)->allows('capability', ['payroll.runs.approve', $w['school']]));
        $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $revoked->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));

        try {
            app(PayrollRunAdministrationService::class)->approve($this->fresh($w), $revoked);
            $this->fail('A revoked approver must be refused at decision time, behind the cache.');
        } catch (AuthorizationException) {
            $this->assertSame('calculated', $this->fresh($w)->status);
        }

        $proof = new FreshMfaProof($approver->id, CarbonImmutable::now());
        $this->assertSame('approved', app(PayrollRunAdministrationService::class)->approve($this->fresh($w), $approver, $proof)->status);

        $metadata = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.approved')->firstOrFail()->metadata);
        $this->assertSame(['payroll_runs', 'approved', $approver->id, 'school', true], [$metadata['approvalWorkflow'], $metadata['approvalDecision'], $metadata['decidedByUserId'], $metadata['decisionScope'], $metadata['freshMfa']]);
        $this->assertSame(['creator' => [$w['creator']->id], 'calculator' => [$w['creator']->id]], $metadata['participants']);
        $this->assertArrayNotHasKey('netAmount', $metadata, 'no pay data in audit');
    }

    #[Test]
    public function a_recalculation_that_leaves_an_employment_unresolved_cannot_be_approved(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $this->paidEmployee($school, $setup);
        $creator = $this->createUser();
        $this->travelTo(Carbon::parse('2015-08-10 06:00:00'));
        $run = $this->inSchool($school, function () use ($school, $creator): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse('2015-08-01'), null, $creator), $creator);
            $run = $this->runs()->createRun($period, $creator);
            $this->runs()->calculate($run, $creator);

            return $run->fresh();
        });

        // A joiner mid-period: the next calculation cannot resolve them without a manual override.
        $joiner = $this->createEmployee($school);
        $record = $this->createEmploymentRecord($joiner, ['status' => 'active', 'starts_on' => '2015-08-15', 'ends_on' => null]);
        $this->inSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $record, $setup['structure'], Carbon::parse('2015-08-15'), [new FixedComponentValueInput($setup['line'], '30000.00')], $setup['actor'],
        ));
        $outcome = $this->inSchool($school, fn () => $this->runs()->calculate($run->fresh(), $this->createUser()));
        $this->travelBack();
        $this->assertNotSame([], $outcome->unresolvedEmploymentRecordIds, 'the joiner is unresolved');
        $this->assertSame('calculated', $this->inSchool($school, fn () => $run->fresh()->status), 'the status cannot go back to draft');

        $this->expectException(RunRecalculationRequiredException::class);
        $this->inSchool($school, fn () => $this->runs()->approve($run->fresh(), $this->createUser()));
    }

    #[Test]
    public function a_correction_delta_author_never_approves_the_correction_run(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $employee = $this->paidEmployee($school, $setup);
        $posted = $this->postedRun($school, '2015-08-01', '2015-08-31');
        $creator = $this->createUser();
        $author = $this->createUser();

        $this->travelTo(Carbon::parse('2015-09-10 06:00:00'));
        $correction = $this->inSchool($school, function () use ($school, $posted, $creator, $author, $employee): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse('2015-09-01'), null, $creator), $creator);
            $correction = $this->runs()->createCorrectionRun($posted, $period, $creator);
            $record = EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail();
            $basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
            $this->runs()->recordCorrectionDelta($correction, $record, [new CorrectionDeltaInput($basic->id, '250.00', 'increase')], 'Arrears', $author);
            $this->runs()->calculate($correction, $creator);

            return $correction->fresh();
        });
        $this->travelBack();

        try {
            $this->inSchool($school, fn () => $this->runs()->approve($correction, $author));
            $this->fail('The delta author must not approve.');
        } catch (SelfApprovalNotAllowedException) {
            $this->assertSame(['creator' => [$creator->id], 'calculator' => [$creator->id], 'adjustment_author' => [$author->id]], $this->runs()->participants($correction)->all());
        }
        $this->assertSame('approved', $this->inSchool($school, fn () => $this->runs()->approve($correction, $this->createUser()))->status);
    }

    #[Test]
    public function only_the_authorized_entry_calls_the_core_approval(): void
    {
        $callers = [];
        foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
            if (str_contains($file->getContents(), 'PayrollRunService') && preg_match('/runs->approve\(|PayrollRunService::class\)->approve\(/', $file->getContents()) === 1) {
                $callers[] = $file->getRelativePathname();
            }
        }
        $this->assertSame(['Domain/Payroll/Application/PayrollRunAdministrationService.php'], $callers, 'every application approval takes current authority first');
    }
}
