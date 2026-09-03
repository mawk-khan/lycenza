<?php

namespace Tests\Feature\Payroll;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotEligibleForPayslipException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotFoundException;
use App\Domain\Payroll\Application\Exceptions\PayslipNotFoundException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Application\Payslip;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.10 -- proves `PayslipReadService`'s authorization/eligibility/
 * disclosure/audit discipline, mirroring
 * `PayrollRunResultReadServiceTest`'s exact shape (same single
 * capability, same "no oracle" cross-School uniformity, same
 * audit-the-fact-never-the-value proof) plus the payslip-specific
 * eligibility boundary and correction/reversal presentation rules.
 */
class PayslipReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Builds a School through 'calculated' status with one resolvable
     * EmploymentRecord, mirroring `PayrollRunResultReadServiceTest::makeCalculatedRun()`.
     *
     * @return array{school: School, runId: string, employmentRecordId: string, structureManager: User, runManager: User}
     */
    private function makeCalculatedRun(): array
    {
        $school = $this->createSchool();
        $context = $this->context();
        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage',
        ]);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);

        $ctx = $context->withSchool($school, function () use ($school, $structureManager, $runManager) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-PAYSLIP', 'Grade Payslip', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $runService = app(PayrollRunAdministrationService::class);
            $run = $runService->createRun($period, $runManager);
            $runService->calculate($run, $runManager);

            return ['runId' => $run->id, 'employmentRecordId' => $employmentRecord->id];
        });

        return ['school' => $school, 'runId' => $ctx['runId'], 'employmentRecordId' => $ctx['employmentRecordId'], 'structureManager' => $structureManager, 'runManager' => $runManager];
    }

    /**
     * @return array{school: School, runId: string, employmentRecordId: string, structureManager: User, runManager: User}
     */
    private function makeApprovedRun(): array
    {
        $f = $this->makeCalculatedRun();
        $approver = $this->createUserWithCapabilities($f['school'], ['payroll.runs.approve']);

        $this->context()->withSchool($f['school'], function () use ($f, $approver) {
            $run = PayrollRun::query()->findOrFail($f['runId']);
            app(PayrollRunAdministrationService::class)->approve($run, $approver);
        });

        return $f;
    }

    /**
     * @return array{school: School, runId: string, employmentRecordId: string, structureManager: User, runManager: User}
     */
    private function makePostedRun(): array
    {
        $f = $this->makeApprovedRun();
        $poster = $this->createUserWithCapabilities($f['school'], ['payroll.runs.post', 'payroll.runs.reverse']);

        $this->context()->withSchool($f['school'], function () use ($f, $poster) {
            $run = PayrollRun::query()->findOrFail($f['runId']);
            app(PayrollPostingAdministrationService::class)->post($run, $poster);
        });

        return $f + ['poster' => $poster];
    }

    #[Test]
    public function an_approved_regular_result_renders_and_the_read_is_audited(): void
    {
        $f = $this->makeApprovedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);

        $payslip = app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);

        $this->assertSame('regular', $payslip->runKind);
        $this->assertSame('approved', $payslip->runStatus);
        $this->assertFalse($payslip->isReversed);
        $this->assertSame('50000.00', $payslip->grossAmount);
        $this->assertSame('0.00', $payslip->totalDeductions);
        $this->assertSame('50000.00', $payslip->netAmount);
        $this->assertCount(1, $payslip->lines);
        $this->assertFalse($payslip->statutoryDeductionsIncluded);

        $event = $this->context()->withSchool(
            $f['school'],
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.payslip.viewed')->where('subject_id', $f['runId'])->first(),
        );
        $this->assertNotNull($event);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertArrayNotHasKey('grossAmount', (array) $event->metadata);
        $this->assertArrayNotHasKey('netAmount', (array) $event->metadata);
    }

    #[Test]
    public function a_posted_regular_result_renders(): void
    {
        $f = $this->makePostedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);

        $payslip = app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);

        $this->assertSame('posted', $payslip->runStatus);
        $this->assertFalse($payslip->isReversed);
    }

    #[Test]
    public function a_draft_or_calculated_result_cannot_render_as_a_final_payslip(): void
    {
        $f = $this->makeCalculatedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);

        $this->expectException(PayrollRunNotEligibleForPayslipException::class);

        app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);
    }

    #[Test]
    public function a_correction_result_clearly_renders_as_a_correction(): void
    {
        $f = $this->makePostedRun();
        $periodService = app(PayrollPeriodAdministrationService::class);
        $runService = app(PayrollRunAdministrationService::class);

        $correctionRunId = $this->context()->withSchool($f['school'], function () use ($f, $periodService, $runService) {
            $original = PayrollRun::query()->findOrFail($f['runId']);
            $correctionPeriod = $periodService->open($periodService->createPeriod($f['school'], Carbon::parse('2026-10-01'), null, $f['runManager']), $f['runManager']);
            $correction = $runService->createCorrectionRun($original, $correctionPeriod, $f['runManager']);

            $employmentRecord = EmploymentRecord::query()->findOrFail($f['employmentRecordId']);
            $runService->recordCorrectionDelta(
                $correction, $employmentRecord,
                [new CorrectionDeltaInput($this->firstEarningComponentId($f['school']), '1000.00', 'increase')],
                'Payslip test correction', $f['runManager'],
            );
            $runService->calculate($correction, $f['runManager']);

            $approver = $this->createUserWithCapabilities($f['school'], ['payroll.runs.approve']);
            // calculate() mutates the row in the database, not this
            // in-memory $correction instance -- refresh before the
            // status-sensitive approve() call.
            $runService->approve($correction->refresh(), $approver);

            return $correction->id;
        });

        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);
        $payslip = app(PayslipReadService::class)->render($f['school'], $correctionRunId, $f['employmentRecordId'], $actor);

        $this->assertSame('correction', $payslip->runKind);
        $this->assertSame($f['runId'], $payslip->correctsPayrollRunId);
        $this->assertNotNull($payslip->correctsPayrollPeriodMonth);
        $this->assertSame('1000.00', $payslip->netAmount);
    }

    #[Test]
    public function a_reversed_posted_run_remains_renderable_with_a_derived_reversal_indicator(): void
    {
        $f = $this->makePostedRun();

        $this->context()->withSchool($f['school'], function () use ($f) {
            $run = PayrollRun::query()->findOrFail($f['runId']);
            app(PayrollPostingAdministrationService::class)->reverse($run, $f['poster'], 'test reversal');
        });

        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);
        $payslip = app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);

        $this->assertTrue($payslip->isReversed);
        // The historical result is never rewritten to zero.
        $this->assertSame('50000.00', $payslip->netAmount);
    }

    #[Test]
    public function an_actor_with_no_capability_at_all_is_denied(): void
    {
        $f = $this->makeApprovedRun();
        $actor = $this->createUserWithCapabilities($f['school'], []);

        $this->expectException(AuthorizationException::class);

        app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);
    }

    #[Test]
    public function payroll_runs_view_alone_is_not_sufficient(): void
    {
        $f = $this->makeApprovedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.runs.view']);

        $this->expectException(AuthorizationException::class);

        app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);
    }

    #[Test]
    public function payroll_compensation_sensitive_view_is_sufficient_on_its_own(): void
    {
        $f = $this->makeApprovedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);

        $payslip = app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);

        $this->assertInstanceOf(Payslip::class, $payslip);
    }

    #[Test]
    public function a_cross_school_run_id_raises_the_same_not_found_error_as_a_nonexistent_one(): void
    {
        $f = $this->makeApprovedRun();
        $otherSchool = $this->createSchool();
        $actor = $this->createUserWithCapabilities($otherSchool, ['payroll.compensation.sensitive.view']);

        $this->expectException(PayrollRunNotFoundException::class);

        app(PayslipReadService::class)->render($otherSchool, $f['runId'], $f['employmentRecordId'], $actor);
    }

    #[Test]
    public function an_employment_record_with_no_result_on_the_run_is_not_found(): void
    {
        $f = $this->makeApprovedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);
        $otherEmploymentRecord = $this->createEmploymentRecord($this->createEmployee($f['school']));

        $this->expectException(PayslipNotFoundException::class);

        app(PayslipReadService::class)->render($f['school'], $f['runId'], $otherEmploymentRecord->id, $actor);
    }

    #[Test]
    public function the_dto_never_carries_a_bank_or_statutory_field(): void
    {
        $f = $this->makeApprovedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);

        $payslip = app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);

        $properties = array_map(fn ($p) => $p->getName(), (new ReflectionClass($payslip))->getProperties());
        foreach (['bankAccount', 'bankAccountNumber', 'ifsc', 'pan', 'pf', 'esi', 'tds', 'aadhaar', 'panNumber'] as $forbidden) {
            $this->assertNotContains($forbidden, $properties, "Payslip must never carry a bank/statutory field ({$forbidden}).");
        }
        $this->assertFalse($payslip->statutoryDeductionsIncluded, 'Statutory deductions must never be claimed as included while Checkpoint 9.6 is deferred.');
    }

    #[Test]
    public function rendering_a_payslip_never_creates_a_stored_document(): void
    {
        $f = $this->makeApprovedRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);
        $countDocuments = fn () => $this->context()->withSchool($f['school'], fn () => Document::query()->where('school_id', $f['school']->id)->count());
        $before = $countDocuments();

        app(PayslipReadService::class)->render($f['school'], $f['runId'], $f['employmentRecordId'], $actor);

        $this->assertSame($before, $countDocuments(), 'Phase 9 v1 payslip rendering must never persist a Document -- render-on-demand only.');
    }

    private function firstEarningComponentId(School $school): string
    {
        return SalaryComponent::query()
            ->where('school_id', $school->id)
            ->where('type', 'earning')
            ->firstOrFail()
            ->id;
    }
}
