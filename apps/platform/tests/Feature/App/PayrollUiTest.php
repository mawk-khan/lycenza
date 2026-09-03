<?php

namespace Tests\Feature\App;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.9 -- the administrative Payroll Inertia UI
 * (App\Http\Controllers\App\Payroll\*). Backend authorization/tenant-
 * safety/domain-invariant correctness is already proven by the JSON API
 * test suite (PayrollApiTest, PayrollIdempotencyTest, the various
 * Payroll concurrency tests) -- these tests cover the Inertia-specific
 * integration: page rendering, capability-aware props, and -- the
 * highest-risk requirement of this checkpoint -- that Highly Sensitive
 * compensation/result amounts are structurally ABSENT from the
 * serialized page payload for an actor lacking
 * payroll.compensation.sensitive.view, not merely hidden by a Vue
 * conditional. Mirrors HrUiTest's exact pattern (memberWith()/activate()
 * helpers, assertInertia(), ->has('prop', 0) for "present but empty").
 */
class PayrollUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function memberWith(array $capabilities, School $school): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->activate($user, $school);

        return $user;
    }

    /**
     * Builds a School with an active Salary Structure, one fixed
     * component assigned to one EmploymentRecord, and an OPEN period --
     * everything needed to create/calculate a run, but nothing beyond
     * that (tests extend from here as needed).
     *
     * @return array{school: School, employmentRecordId: string, periodId: string, structureManager: User}
     */
    private function makeStructureAndPeriod(School $school): array
    {
        $structureManager = $this->memberWith([
            'payroll.structures.manage', 'payroll.compensation.sensitive.manage', 'payroll.accounting.manage',
        ], $school);
        $periodManager = $this->memberWith(['payroll.periods.manage'], $school);

        $context = app(TenantContext::class);

        return $context->withSchool($school, function () use ($school, $structureManager, $periodManager) {
            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-UI', 'Grade UI', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure->fresh(), Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-10-01'), null, $periodManager), $periodManager);

            return [
                'school' => $school,
                'employmentRecordId' => $employmentRecord->id,
                'periodId' => $period->id,
                'structureManager' => $structureManager,
            ];
        });
    }

    private function makeCalculatedRun(School $school, string $periodId, User $preparer): string
    {
        $context = app(TenantContext::class);
        $runService = app(PayrollRunAdministrationService::class);

        return $context->withSchool($school, function () use ($periodId, $preparer, $runService) {
            $period = PayrollPeriod::query()->findOrFail($periodId);
            $run = $runService->createRun($period, $preparer);
            $runService->calculate($run, $preparer);

            return $run->id;
        });
    }

    // --- Navigation ----------------------------------------------------

    #[Test]
    public function payroll_nav_is_hidden_without_any_payroll_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith([], $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('nav.canViewPayroll', false));
    }

    #[Test]
    public function payroll_nav_is_shown_with_a_single_payroll_view_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith(['payroll.runs.view'], $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('nav.canViewPayroll', true));
    }

    // --- Structures: authorization + immutability -----------------------

    #[Test]
    public function structures_view_only_gets_read_only_access_with_no_management_controls(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $viewer = $this->memberWith(['payroll.structures.view'], $school);

        $this->actingAs($viewer)->get('/app/payroll/structures')->assertInertia(fn ($page) => $page
            ->component('App/Payroll/Structures/Index')
            ->where('canManage', false)
            ->has('structures', 1)
        );
    }

    #[Test]
    public function structures_manage_gets_management_controls(): void
    {
        $school = $this->createSchool();
        $this->makeStructureAndPeriod($school);
        $manager = $this->memberWith(['payroll.structures.view', 'payroll.structures.manage'], $school);

        $this->actingAs($manager)->get('/app/payroll/structures')->assertInertia(fn ($page) => $page
            ->where('canManage', true)
        );
    }

    #[Test]
    public function an_active_structure_is_immutable_even_for_an_actor_holding_manage(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $manager = $this->memberWith(['payroll.structures.view', 'payroll.structures.manage'], $school);
        $structure = app(TenantContext::class)->withSchool($school, fn () => SalaryStructure::query()->where('school_id', $school->id)->firstOrFail());

        $this->actingAs($manager)->get("/app/payroll/structures/{$structure->id}")->assertInertia(fn ($page) => $page
            ->where('structure.status', 'active')
            ->where('canManage', false)
        );
    }

    // --- Runs: authorization tiers ---------------------------------------

    #[Test]
    public function runs_view_without_prepare_has_no_preparation_controls(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $viewer = $this->memberWith(['payroll.runs.view'], $school);

        $this->actingAs($viewer)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('canPrepare', false)
        );
    }

    #[Test]
    public function prepare_without_approve_has_no_approval_authority(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);

        $this->actingAs($preparer)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('canApprove', false)
        );
    }

    #[Test]
    public function the_preparer_cannot_approve_their_own_run_and_the_ui_explains_why(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        // Holds BOTH capabilities -- the actor-level SoD rule must still
        // block self-approval regardless of capability grants.
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view', 'payroll.runs.approve'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);

        $this->actingAs($preparer)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('isSelfPrepared', true)
            ->where('canApprove', false)
        );
    }

    #[Test]
    public function a_different_authorized_actor_can_approve(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.runs.view'], $school);

        $this->actingAs($approver)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('canApprove', true)
            ->where('isSelfPrepared', false)
        );

        $this->actingAs($approver)->post("/app/payroll/runs/{$runId}/approve")->assertRedirect("/app/payroll/runs/{$runId}");
        $status = app(TenantContext::class)->withSchool($school, fn () => PayrollRun::query()->findOrFail($runId)->status);
        $this->assertSame('approved', $status);
    }

    #[Test]
    public function post_and_reverse_are_independently_capability_gated(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $viewer = $this->memberWith(['payroll.runs.view'], $school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $approver = $this->memberWith(['payroll.runs.approve'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver));

        $this->actingAs($viewer)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('canPost', false)
            ->where('canReverse', false)
        );

        $poster = $this->memberWith(['payroll.runs.view', 'payroll.runs.post'], $school);
        $this->actingAs($poster)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('canPost', true)
            ->where('canReverse', false)
        );
    }

    // --- Sensitive data: never transmitted without the capability -------

    #[Test]
    public function compensation_view_without_sensitive_view_receives_no_amount_fields(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $viewer = $this->memberWith(['payroll.compensation.view'], $school);

        $response = $this->actingAs($viewer)->get("/app/payroll/compensation/{$fixtures['employmentRecordId']}");

        $response->assertInertia(fn ($page) => $page
            ->has('assignments', 1)
            ->where('canViewSensitive', false)
        );

        // Belt-and-suspenders: scan the raw transmitted JSON for any
        // amount-shaped key -- this proves the value was never
        // serialized at all, not merely hidden by a Vue v-if.
        $raw = $response->getContent();
        $this->assertStringNotContainsString('50000.00', $raw);
        $this->assertStringNotContainsString('"amount"', $raw);
    }

    #[Test]
    public function compensation_sensitive_view_can_reveal_amounts_on_demand(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $sensitiveViewer = $this->memberWith(['payroll.compensation.view', 'payroll.compensation.sensitive.view'], $school);

        $assignmentId = app(TenantContext::class)->withSchool($school, fn () => EmployeeCompensationAssignment::query()
            ->where('school_id', $school->id)->where('employment_record_id', $fixtures['employmentRecordId'])->firstOrFail()->id);

        $response = $this->actingAs($sensitiveViewer)
            ->getJson("/app/payroll/compensation-assignments/{$assignmentId}/values");

        $response->assertOk();
        $this->assertSame('50000.00', $response->json('data.0.amount'));
    }

    #[Test]
    public function runs_view_without_sensitive_view_receives_no_gross_net_deduction_fields(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $viewer = $this->memberWith(['payroll.runs.view'], $school);

        $response = $this->actingAs($viewer)->get("/app/payroll/runs/{$runId}");

        $response->assertInertia(fn ($page) => $page
            ->where('canViewSensitive', false)
            ->where('results', null)
        );

        $raw = $response->getContent();
        $this->assertStringNotContainsString('50000.00', $raw);
        $this->assertStringNotContainsString('grossAmount', $raw);
        $this->assertStringNotContainsString('netAmount', $raw);
    }

    #[Test]
    public function sensitive_view_receives_the_expected_financial_detail(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $sensitiveViewer = $this->memberWith(['payroll.runs.view', 'payroll.compensation.sensitive.view'], $school);

        $this->actingAs($sensitiveViewer)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('canViewSensitive', true)
            ->has('results', 1)
            ->where('results.0.grossAmount', '50000.00')
            ->where('results.0.netAmount', '50000.00')
        );
    }

    // --- Lifecycle: draft -> calculate -> approve -> post -> reverse -> correction --

    #[Test]
    public function the_full_run_lifecycle_succeeds_through_the_ui(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $structureManager = $fixtures['structureManager'];

        $expense = app(TenantContext::class)->withSchool($school, fn () => LedgerAccount::factory()->for($school, 'school')->type('expense')->create());
        $payable = app(TenantContext::class)->withSchool($school, fn () => LedgerAccount::factory()->for($school, 'school')->type('liability')->create());
        app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $approver = $this->memberWith(['payroll.runs.approve'], $school);
        $poster = $this->memberWith(['payroll.runs.post', 'payroll.runs.view'], $school);
        $reverser = $this->memberWith(['payroll.runs.reverse'], $school);
        $periodManager = $this->memberWith(['payroll.periods.manage'], $school);

        // draft -> calculate
        $this->actingAs($preparer)->post("/app/payroll/periods/{$fixtures['periodId']}/runs")->assertRedirect();
        $run = app(TenantContext::class)->withSchool($school, fn () => PayrollRun::query()->where('school_id', $school->id)->where('payroll_period_id', $fixtures['periodId'])->firstOrFail());

        $this->actingAs($preparer)->post("/app/payroll/runs/{$run->id}/calculate")->assertInertia(fn ($page) => $page
            ->where('calculationOutcome.transitionedToCalculated', true)
        );
        $this->assertSame('calculated', app(TenantContext::class)->withSchool($school, fn () => $run->fresh())->status);

        // approve
        $this->actingAs($approver)->post("/app/payroll/runs/{$run->id}/approve")->assertRedirect();
        $this->assertSame('approved', app(TenantContext::class)->withSchool($school, fn () => $run->fresh())->status);

        // post
        $this->actingAs($poster)->post("/app/payroll/runs/{$run->id}/post")->assertRedirect();
        $this->assertSame('posted', app(TenantContext::class)->withSchool($school, fn () => $run->fresh())->status);
        $journalCount = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->where('school_id', $school->id)->count());
        $this->assertSame(1, $journalCount);

        // correction run creation -- must happen BEFORE reversal: a
        // correction targets a posted-and-not-yet-reversed original
        // (CorrectionTargetAlreadyReversedException), the reverse
        // direction is what stays unrestricted (ADR 0034).
        $openPeriod = app(TenantContext::class)->withSchool($school, fn () => app(PayrollPeriodAdministrationService::class)->open(
            app(PayrollPeriodAdministrationService::class)->createPeriod($school, Carbon::parse('2026-11-01'), null, $periodManager),
            $periodManager,
        ));
        $this->actingAs($preparer)
            ->post("/app/payroll/runs/{$run->id}/correction", ['payroll_period_id' => $openPeriod->id])
            ->assertRedirect();

        $correctionCount = app(TenantContext::class)->withSchool($school, fn () => PayrollRun::query()
            ->where('school_id', $school->id)->where('corrects_payroll_run_id', $run->id)->count());
        $this->assertSame(1, $correctionCount);

        // reverse -- still allowed even though a correction now exists.
        $this->actingAs($reverser)->post("/app/payroll/runs/{$run->id}/reverse", ['reason' => 'ui test reversal'])->assertRedirect();
        $this->actingAs($poster)->get("/app/payroll/runs/{$run->id}")->assertInertia(fn ($page) => $page
            ->where('run.isReversed', true)
        );
    }

    // --- Manual override / partial-period flow ---------------------------

    #[Test]
    public function calculation_flags_an_unresolved_employment_record_for_manual_input(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $structureManager = $fixtures['structureManager'];
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);

        $context = app(TenantContext::class);
        $run = $context->withSchool($school, function () use ($school, $fixtures, $structureManager, $preparer) {
            // A second EmploymentRecord hired mid-period (starts_on AFTER
            // the period start) with a compensation assignment -- the
            // engine flags a mid-period hire as needing manual input
            // regardless of whether an assignment exists (ADR 0034
            // fail-closed partial-period rule), since no proration is
            // computed automatically.
            $structure = SalaryStructure::query()->where('school_id', $school->id)->firstOrFail();
            $structureComponentId = $structure->components()->firstOrFail()->id;
            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2026-10-15']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2026-10-15'),
                [new FixedComponentValueInput($structureComponentId, '30000.00')], $structureManager,
            );

            $period = PayrollPeriod::query()->findOrFail($fixtures['periodId']);

            return app(PayrollRunAdministrationService::class)->createRun($period, $preparer);
        });

        $this->actingAs($preparer)->post("/app/payroll/runs/{$run->id}/calculate")->assertInertia(fn ($page) => $page
            ->where('calculationOutcome.transitionedToCalculated', false)
            ->has('unresolvedEmployees', 1)
        );
        $status = $context->withSchool($school, fn () => $run->fresh()->status);
        $this->assertSame('draft', $status);
    }

    // --- Accounting configuration presentation ---------------------------

    #[Test]
    public function missing_accounting_configuration_disables_posting_with_an_explanation(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $approver = $this->memberWith(['payroll.runs.approve'], $school);
        $poster = $this->memberWith(['payroll.runs.post', 'payroll.runs.view'], $school);

        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver));

        // No accounting configuration was ever created for this School.
        $this->actingAs($poster)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('accountingReadiness.configured', false)
            ->where('accountingReadiness.readyToPost', false)
        );
    }

    // --- Payroll periods: monthly-only controls ---------------------------

    #[Test]
    public function period_index_shows_month_dates_and_status_without_arbitrary_date_range_editing(): void
    {
        $school = $this->createSchool();
        $this->makeStructureAndPeriod($school);
        $manager = $this->memberWith(['payroll.periods.manage', 'payroll.runs.view'], $school);

        $this->actingAs($manager)->get('/app/payroll/periods')->assertInertia(fn ($page) => $page
            ->component('App/Payroll/Periods/Index')
            ->has('periods', 1)
            ->where('periods.0.periodMonth', '2026-10-01')
            ->where('periods.0.status', 'open')
        );
    }

    // --- Payslip rendering (Phase 9.10) ------------------------------------

    #[Test]
    public function the_payslip_page_renders_the_expected_props_for_an_approved_run(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $approver = $this->memberWith(['payroll.runs.approve'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver));

        $sensitiveViewer = $this->memberWith(['payroll.compensation.sensitive.view'], $school);
        $response = $this->actingAs($sensitiveViewer)->get("/app/payroll/runs/{$runId}/payslips/{$fixtures['employmentRecordId']}");

        $response->assertInertia(fn ($page) => $page
            ->component('App/Payroll/Payslips/Show')
            ->where('payslip.runStatus', 'approved')
            ->where('payslip.isReversed', false)
            ->where('payslip.netAmount', '50000.00')
            ->where('payslip.statutoryDeductionsIncluded', false)
        );

        // Belt-and-suspenders, matching this file's own established
        // pattern above: the raw payload never carries a bank/statutory
        // field, not merely a Vue template that never renders one.
        $raw = $response->getContent();
        foreach (['bankAccount', 'ifsc', 'panNumber', 'pf', 'esi', 'tds'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    #[Test]
    public function the_payslip_page_is_forbidden_without_sensitive_view(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $approver = $this->memberWith(['payroll.runs.approve'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver));

        $viewer = $this->memberWith(['payroll.runs.view'], $school);
        $this->actingAs($viewer)->get("/app/payroll/runs/{$runId}/payslips/{$fixtures['employmentRecordId']}")
            ->assertForbidden();
    }

    #[Test]
    public function the_payslip_page_422s_for_a_still_draft_or_calculated_run(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);

        $sensitiveViewer = $this->memberWith(['payroll.compensation.sensitive.view'], $school);
        $this->actingAs($sensitiveViewer)->get("/app/payroll/runs/{$runId}/payslips/{$fixtures['employmentRecordId']}")
            ->assertStatus(422);
    }
}
