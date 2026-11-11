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
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
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
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    /** SR.4 (ADR 0071 §26.7): payroll approve/post/reverse and compensation writes; sensitive payroll reads -- each request gets a fresh code or current MFA assurance. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->applySensitiveActionMfa($method, $uri, $parameters, $content, '#^/app/payroll/(runs/[^/]+/(approve|post|reverse)|compensation/[^/]+)$#', '#^/app/payroll/(runs/[^/]+(/payslips/[^/]+)?|compensation/[^/]+|compensation-assignments/[^/]+/values)$#');

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

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
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager, $this->freshMfaProofFor($structureManager),
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

    // --- HR.R1-C14 (C12-N3): a capability refusal never depends on the record -------

    /**
     * The five web actions that looked a record up before asking the capability: a same-School member without it got
     * 404 for an unknown id and 403 for a real one, so a known UUID could be tested for existence.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> method, the capability, the site
     */
    public static function capabilityFirstSites(): array
    {
        return [
            'compensation show' => ['get', 'payroll.compensation.view', 'compensation.show'],
            'compensation store' => ['post', 'payroll.compensation.sensitive.manage', 'compensation.store'],
            'statutory employee show' => ['get', 'payroll.statutory.view', 'statutory.show'],
            'manual override' => ['post', 'payroll.runs.prepare', 'manual-override'],
            'correction delta' => ['post', 'payroll.runs.prepare', 'correction-delta'],
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function siteRequest(string $site, string $runId, string $employmentRecordId): array
    {
        return match ($site) {
            'compensation.show', 'compensation.store' => ["/app/payroll/compensation/{$employmentRecordId}", ['salary_structure_id' => (string) Str::uuid7(), 'effective_from' => '2026-01-01', 'fixed_values' => []]],
            'statutory.show' => ["/app/payroll/statutory/employees/{$employmentRecordId}", []],
            'manual-override' => ["/app/payroll/runs/{$runId}/manual-overrides", ['employment_record_id' => $employmentRecordId, 'component_amounts' => ['x' => '1.00'], 'reason' => 'Why']],
            'correction-delta' => ["/app/payroll/runs/{$runId}/correction-deltas", ['employment_record_id' => $employmentRecordId, 'lines' => [['salary_component_id' => (string) Str::uuid7(), 'amount' => '1.00', 'effect' => 'increase']], 'reason' => 'Why']],
        };
    }

    #[Test]
    #[DataProvider('capabilityFirstSites')]
    public function a_member_without_the_capability_gets_the_same_403_for_a_real_id_and_an_unknown_one(string $method, string $capability, string $site): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $stranger = $this->memberWith([], $school);
        $unknown = (string) Str::uuid7();

        foreach ([[$runId, $fixtures['employmentRecordId']], [$unknown, $fixtures['employmentRecordId']], [$runId, $unknown], [$unknown, $unknown]] as [$run, $employment]) {
            [$uri, $body] = $this->siteRequest($site, $run, $employment);
            $response = $method === 'get' ? $this->actingAs($stranger)->get($uri) : $this->actingAs($stranger)->post($uri, $body);

            $response->assertForbidden();
            $this->assertStringNotContainsString($fixtures['employmentRecordId'], $response->getContent(), 'the refusal says nothing of the record');
        }
    }

    #[Test]
    #[DataProvider('capabilityFirstSites')]
    public function a_holder_of_the_capability_still_gets_the_private_404_for_an_unknown_or_foreign_id(string $method, string $capability, string $site): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $holder = $this->memberWith([$capability, 'payroll.compensation.view'], $school);

        $foreignSchool = $this->createSchool();
        $foreign = $this->createEmploymentRecord($this->createEmployee($foreignSchool), ['starts_on' => '2025-01-01']);

        foreach ([(string) Str::uuid7(), $foreign->id] as $missing) {
            [$uri, $body] = $this->siteRequest($site, $runId, $missing);
            $response = $method === 'get' ? $this->actingAs($holder)->get($uri) : $this->actingAs($holder)->post($uri, $body);

            $response->assertNotFound();
        }
    }

    #[Test]
    #[DataProvider('capabilityFirstSites')]
    public function a_suspended_school_answers_every_member_the_same_whatever_the_id(string $method, string $capability, string $site): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $stranger = $this->memberWith([], $school);
        $holder = $this->memberWith([$capability, 'payroll.compensation.view'], $school);
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);

        $statuses = [];
        foreach ([$stranger, $holder] as $actor) {
            foreach ([$fixtures['employmentRecordId'], (string) Str::uuid7()] as $employment) {
                [$uri, $body] = $this->siteRequest($site, $runId, $employment);
                $statuses[] = ($method === 'get' ? $this->actingAs($actor)->get($uri) : $this->actingAs($actor)->post($uri, $body))->getStatusCode();
            }
        }

        $this->assertCount(1, array_unique($statuses), 'nobody can tell a suspended School\'s records apart: '.implode(',', $statuses));
    }

    #[Test]
    public function an_unknown_employment_never_spends_a_fresh_code_on_a_compensation_write(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $writer = $this->memberWith(['payroll.compensation.sensitive.manage', 'payroll.compensation.view'], $school);
        $code = $this->freshMfaCode($writer);
        $unspent = fn () => DB::table('user_mfa_recovery_codes')->where('user_id', $writer->id)->whereNull('consumed_at')->count();
        $before = $unspent();

        $this->actingAs($writer)->post('/app/payroll/compensation/'.Str::uuid7(), ['salary_structure_id' => (string) Str::uuid7(), 'effective_from' => '2026-01-01', 'fixed_values' => [], 'mfa_code' => $code])->assertNotFound();

        $this->assertGreaterThan(0, $before);
        $this->assertSame($before, $unspent(), 'the code was never asked for');
    }

    #[Test]
    public function the_search_treats_a_wildcard_as_text_and_answers_in_a_stable_order(): void
    {
        $school = $this->createSchool();
        $viewer = $this->memberWith(['payroll.compensation.view', 'payroll.statutory.view'], $school);
        foreach (['Anil Kumar', 'Anil_Kumar', '100% Anil'] as $name) {
            $this->createEmploymentRecord($this->createEmployee($school, ['full_name' => $name]), ['starts_on' => '2025-01-01']);
        }
        $names = fn (string $url) => array_column($this->actingAs($viewer)->getJson($url)->assertOk()->json('data'), 'employeeFullName');

        foreach (['/app/payroll/compensation/search', '/app/payroll/statutory/employees/search'] as $url) {
            $this->assertSame(['100% Anil'], $names("{$url}?q=".urlencode('0%')), 'a percent sign matches a percent sign');
            $this->assertSame(['Anil_Kumar'], $names("{$url}?q=".urlencode('l_K')), 'an underscore matches an underscore');
            $this->assertCount(3, $names("{$url}?q=Anil"));
            $ids = array_column($this->actingAs($viewer)->getJson("{$url}?q=Anil")->json('data'), 'employmentRecordId');
            $sorted = $ids;
            sort($sorted);
            $this->assertSame($sorted, $ids, 'a bounded list has a defined order');
        }
    }

    // --- Navigation ----------------------------------------------------

    #[Test]
    public function payroll_nav_is_hidden_without_any_payroll_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith([], $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(hidden: ['payroll'])));
    }

    #[Test]
    public function payroll_nav_is_shown_with_a_single_payroll_view_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith(['payroll.runs.view'], $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: ['payroll'])));
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
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view', 'payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
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
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view'], $school);

        $this->actingAs($approver)->get("/app/payroll/runs/{$runId}")->assertInertia(fn ($page) => $page
            ->where('canApprove', true)
            ->where('isSelfPrepared', false)
        );

        $this->actingAs($approver)->post("/app/payroll/runs/{$runId}/approve", ['calculation_id' => app(PayrollRunService::class)->latestCalculationId(app(TenantContext::class)->withSchool($school, fn () => PayrollRun::query()->findOrFail($runId))), 'attribution_version' => app(PayrollRunService::class)->latestAttributionVersion(app(TenantContext::class)->withSchool($school, fn () => PayrollRun::query()->findOrFail($runId)))])->assertRedirect("/app/payroll/runs/{$runId}/review");
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
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver, $this->freshMfaProofFor($approver), app(PayrollRunService::class)->latestCalculationId(PayrollRun::query()->findOrFail($runId)), app(PayrollRunService::class)->latestAttributionVersion(PayrollRun::query()->findOrFail($runId))));

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
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
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
        $this->actingAs($approver)->post("/app/payroll/runs/{$run->id}/approve", ['calculation_id' => app(PayrollRunService::class)->latestCalculationId($run), 'attribution_version' => app(PayrollRunService::class)->latestAttributionVersion($run)])->assertRedirect("/app/payroll/runs/{$run->id}/review");
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
                [new FixedComponentValueInput($structureComponentId, '30000.00')], $structureManager, $this->freshMfaProofFor($structureManager),
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
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
        $poster = $this->memberWith(['payroll.runs.post', 'payroll.runs.view'], $school);

        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver, $this->freshMfaProofFor($approver), app(PayrollRunService::class)->latestCalculationId(PayrollRun::query()->findOrFail($runId)), app(PayrollRunService::class)->latestAttributionVersion(PayrollRun::query()->findOrFail($runId))));

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
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver, $this->freshMfaProofFor($approver), app(PayrollRunService::class)->latestCalculationId(PayrollRun::query()->findOrFail($runId)), app(PayrollRunService::class)->latestAttributionVersion(PayrollRun::query()->findOrFail($runId))));

        // HR.R1-C14 (C11-N3): the address that failed CI once. It holds `pf`, so a search of the whole page was never
        // a statement about the payslip. Kept on purpose, so the check below can only pass by reading the payload.
        $sensitiveViewer = $this->memberWith(['payroll.compensation.sensitive.view'], $school);
        DB::table('users')->where('id', $sensitiveViewer->id)->update(['email' => 'gdapfw.varun.mistry@example.org']);
        $response = $this->actingAs($sensitiveViewer->fresh())->get("/app/payroll/runs/{$runId}/payslips/{$fixtures['employmentRecordId']}");

        $response->assertInertia(fn ($page) => $page
            ->component('App/Payroll/Payslips/Show')
            ->where('payslip.runStatus', 'approved')
            ->where('payslip.isReversed', false)
            ->where('payslip.netAmount', '50000.00')
            ->where('payslip.statutoryDeductionsIncluded', false)
        );

        // Belt-and-suspenders: the payload never carries a bank or statutory field, not merely a Vue template that never
        // renders one. Keys are code and values are data, so this reads keys: `pf` may be part of a person's email.
        $raw = $response->getContent();
        $page = $response->viewData('page');
        $keys = [];
        $walk = function (array $node) use (&$walk, &$keys): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    // `employeePfMandatory` and `employeePFAmount` are the shapes to catch: words, not substrings.
                    $keys[] = strtolower(implode(' ', preg_split('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|(?<=[A-Za-z])(?=[0-9])|[^A-Za-z0-9]+/', $key) ?: []));
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($page['props']);
        $this->assertContains('netamount', array_map(fn (string $key) => str_replace(' ', '', $key), $keys), 'the check reached the payslip');
        foreach (['bank', 'ifsc', 'pan', 'uan', 'esic', 'pf', 'epf', 'eps', 'edli', 'esi', 'tds', 'lwf', 'professional'] as $forbidden) {
            $this->assertSame([], array_values(array_filter($keys, fn (string $key) => in_array($forbidden, explode(' ', $key), true))), "no {$forbidden} field on a payslip with no statutory deductions");
        }
        $this->assertNull($page['props']['payslip']['statutory']);
        $this->assertSame(['Basic'], array_column($page['props']['payslip']['lines'], 'componentName'), 'no statutory line either');
        // These two are distinctive enough that a value can never hold them by accident.
        foreach (['bankAccount', 'panNumber'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    #[Test]
    public function the_payslip_page_names_its_run_by_kind_and_period_never_by_id(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver, $this->freshMfaProofFor($approver), app(PayrollRunService::class)->latestCalculationId(PayrollRun::query()->findOrFail($runId)), app(PayrollRunService::class)->latestAttributionVersion(PayrollRun::query()->findOrFail($runId))));

        // LC-3 (M3): labels come from the run's fixed period month and kind.
        $sensitiveViewer = $this->memberWith(['payroll.compensation.sensitive.view'], $school);
        $this->actingAs($sensitiveViewer)->get("/app/payroll/runs/{$runId}/payslips/{$fixtures['employmentRecordId']}")
            ->assertInertia(fn ($page) => $page
                ->component('App/Payroll/Payslips/Show')
                ->where('labels.periodLabel', 'October 2026')
                ->where('labels.runLabel', 'Regular payroll run · October 2026')
                ->where('labels.correctsPeriodLabel', null)
                ->where('labels.employeeLabel', fn (string $name) => preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/i', $name) === 0));

        // Authorization is unchanged: the labels never open the page to someone else.
        $this->actingAs($this->memberWith(['payroll.runs.view'], $school))
            ->get("/app/payroll/runs/{$runId}/payslips/{$fixtures['employmentRecordId']}")->assertForbidden();
    }

    #[Test]
    public function the_payslip_page_is_forbidden_without_sensitive_view(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver, $this->freshMfaProofFor($approver), app(PayrollRunService::class)->latestCalculationId(PayrollRun::query()->findOrFail($runId)), app(PayrollRunService::class)->latestAttributionVersion(PayrollRun::query()->findOrFail($runId))));

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

    // --- HR.R1-C24 (WO-3, D-2): Payroll names people through HR's reader --------------

    /**
     * The name and number HR holds for each employment, read straight from the tables, so the expectation never
     * goes through the reader under test.
     *
     * @return array<string, array{employeeId: string, employeeFullName: string, employeeNumber: ?string}>
     */
    private function hrFacts(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('employment_records as er')
            ->join('employees as e', 'e.id', '=', 'er.employee_id')->where('er.school_id', $school->id)
            ->get(['er.id', 'er.employee_id', 'e.full_name', 'e.employee_number'])
            ->mapWithKeys(fn ($r) => [$r->id => ['employeeId' => $r->employee_id, 'employeeFullName' => $r->full_name, 'employeeNumber' => $r->employee_number]])->all());
    }

    /**
     * A rehired Employee: an ended first employment and a running second one, compensated, with an account link.
     *
     * @return array{employee: string, first: string, second: string, user: User}
     */
    private function rehire(School $school, User $structureManager): array
    {
        return app(TenantContext::class)->withSchool($school, function () use ($school, $structureManager) {
            $user = $this->createUser();
            $employee = $this->createEmployee($school, ['full_name' => 'Rehired Person', 'user_id' => $user->id]);
            $first = $this->createEmploymentRecord($employee, ['starts_on' => '2023-01-01', 'ends_on' => '2023-12-31']);
            $second = $this->createEmploymentRecord($employee, ['starts_on' => '2025-01-01']);
            $structure = SalaryStructure::query()->where('school_id', $school->id)->firstOrFail();
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $second, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($structure->components()->firstOrFail()->id, '42000.00')], $structureManager, $this->freshMfaProofFor($structureManager),
            );

            return ['employee' => $employee->id, 'first' => $first->id, 'second' => $second->id, 'user' => $user];
        });
    }

    #[Test]
    public function wo3_the_run_page_names_every_result_from_hr_and_a_replaced_account_changes_nothing(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $rehire = $this->rehire($school, $fixtures['structureManager']);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $viewer = $this->memberWith(['payroll.runs.view', 'payroll.compensation.sensitive.view'], $school);
        $facts = $this->hrFacts($school);

        $results = fn () => collect($this->actingAs($viewer)->get("/app/payroll/runs/{$runId}")->viewData('page')['props']['results'])
            ->mapWithKeys(fn (array $r) => [$r['employmentRecordId'] => [$r['employeeFullName'], $r['employeeNumber']]])->sortKeys()->all();
        $before = $results();

        $expected = collect([$fixtures['employmentRecordId'], $rehire['second']])
            ->mapWithKeys(fn (string $id) => [$id => [$facts[$id]['employeeFullName'], $facts[$id]['employeeNumber']]])->sortKeys()->all();
        $this->assertSame($expected, $before, 'each result is named by its own employment, the rehire by the second one');
        $this->assertArrayNotHasKey($rehire['first'], $before, 'the ended first employment is not paid in this period');

        // The Employee's account is unlinked, then another account is linked: names come from the employment, never the User.
        app(TenantContext::class)->withSchool($school, fn () => DB::table('employees')->where('id', $rehire['employee'])->update(['user_id' => null]));
        app(TenantContext::class)->withSchool($school, fn () => DB::table('employees')->where('id', $rehire['employee'])->update(['user_id' => $this->createUser()->id]));
        $this->assertSame($before, $results());
    }

    #[Test]
    public function wo3_an_unresolved_employment_carries_its_hr_label(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);

        $context = app(TenantContext::class);
        [$run, $midPeriodHire] = $context->withSchool($school, function () use ($school, $fixtures, $preparer) {
            $structure = SalaryStructure::query()->where('school_id', $school->id)->firstOrFail();
            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school, ['full_name' => 'Mid Period']), ['starts_on' => '2026-10-15']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2026-10-15'),
                [new FixedComponentValueInput($structure->components()->firstOrFail()->id, '30000.00')], $fixtures['structureManager'], $this->freshMfaProofFor($fixtures['structureManager']),
            );

            return [app(PayrollRunAdministrationService::class)->createRun(PayrollPeriod::query()->findOrFail($fixtures['periodId']), $preparer), $employmentRecord->id];
        });
        $fact = $this->hrFacts($school)[$midPeriodHire];

        $this->actingAs($preparer)->post("/app/payroll/runs/{$run->id}/calculate")->assertInertia(fn ($page) => $page
            ->has('unresolvedEmployees', 1)
            ->where('unresolvedEmployees.0', [
                'employmentRecordId' => $midPeriodHire,
                'employeeId' => $fact['employeeId'],
                'employeeFullName' => 'Mid Period',
                'employeeNumber' => $fact['employeeNumber'],
            ])
        );
    }

    #[Test]
    public function wo3_correction_candidates_are_named_from_hr(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $structureManager = $fixtures['structureManager'];
        $expense = app(TenantContext::class)->withSchool($school, fn () => LedgerAccount::factory()->for($school, 'school')->type('expense')->create());
        $payable = app(TenantContext::class)->withSchool($school, fn () => LedgerAccount::factory()->for($school, 'school')->type('liability')->create());
        app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
        $poster = $this->memberWith(['payroll.runs.post', 'payroll.runs.view'], $school);
        $periodManager = $this->memberWith(['payroll.periods.manage'], $school);

        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $run = app(TenantContext::class)->withSchool($school, fn () => PayrollRun::query()->findOrFail($runId));
        $this->actingAs($approver)->post("/app/payroll/runs/{$runId}/approve", ['calculation_id' => app(PayrollRunService::class)->latestCalculationId($run), 'attribution_version' => app(PayrollRunService::class)->latestAttributionVersion($run)])->assertRedirect();
        $this->actingAs($poster)->post("/app/payroll/runs/{$runId}/post")->assertRedirect();
        $openPeriod = app(TenantContext::class)->withSchool($school, fn () => app(PayrollPeriodAdministrationService::class)->open(
            app(PayrollPeriodAdministrationService::class)->createPeriod($school, Carbon::parse('2026-11-01'), null, $periodManager), $periodManager,
        ));
        $this->actingAs($preparer)->post("/app/payroll/runs/{$runId}/correction", ['payroll_period_id' => $openPeriod->id])->assertRedirect();
        $correctionId = app(TenantContext::class)->withSchool($school, fn () => PayrollRun::query()->where('corrects_payroll_run_id', $runId)->value('id'));

        $viewer = $this->memberWith(['payroll.runs.view', 'payroll.compensation.sensitive.view'], $school);
        $this->actingAs($viewer)->get("/app/payroll/runs/{$correctionId}")->assertInertia(fn ($page) => $page
            ->where('correctionCandidates', [[
                'employmentRecordId' => $fixtures['employmentRecordId'],
                'employeeFullName' => $this->hrFacts($school)[$fixtures['employmentRecordId']]['employeeFullName'],
            ]])
        );
    }

    #[Test]
    public function wo3_compensation_and_statutory_pages_name_ended_and_rehired_employments(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $rehire = $this->rehire($school, $fixtures['structureManager']);
        $separated = app(TenantContext::class)->withSchool($school, fn () => $this->createEmploymentRecord(
            $this->createEmployee($school, ['full_name' => 'Left Already']), ['starts_on' => '2024-01-01', 'ends_on' => '2025-06-30'],
        )->id);
        $facts = $this->hrFacts($school);
        $viewer = $this->memberWith(['payroll.compensation.view', 'payroll.statutory.view'], $school);

        foreach ([$rehire['first'], $rehire['second'], $separated, $fixtures['employmentRecordId']] as $id) {
            $this->actingAs($viewer)->get("/app/payroll/compensation/{$id}")->assertInertia(fn ($page) => $page
                ->where('employmentRecord', ['id' => $id] + $facts[$id])
                ->has('assignments', $id === $rehire['second'] || $id === $fixtures['employmentRecordId'] ? 1 : 0)
            );
            $this->actingAs($viewer)->get("/app/payroll/statutory/employees/{$id}")->assertInertia(fn ($page) => $page
                ->where('employmentRecord', ['id' => $id, 'employeeFullName' => $facts[$id]['employeeFullName'], 'employeeNumber' => $facts[$id]['employeeNumber']])
            );
        }
        $this->assertSame($facts[$rehire['first']]['employeeId'], $facts[$rehire['second']]['employeeId'], 'one Employee, two employments');
    }

    #[Test]
    public function wo3_the_payslip_of_a_rehired_employee_keeps_its_name_after_the_account_changes(): void
    {
        $school = $this->createSchool();
        $fixtures = $this->makeStructureAndPeriod($school);
        $rehire = $this->rehire($school, $fixtures['structureManager']);
        $preparer = $this->memberWith(['payroll.runs.prepare', 'payroll.runs.view'], $school);
        $runId = $this->makeCalculatedRun($school, $fixtures['periodId'], $preparer);
        $approver = $this->memberWith(['payroll.runs.approve', 'payroll.compensation.sensitive.view'], $school);
        app(TenantContext::class)->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($runId), $approver, $this->freshMfaProofFor($approver), app(PayrollRunService::class)->latestCalculationId(PayrollRun::query()->findOrFail($runId)), app(PayrollRunService::class)->latestAttributionVersion(PayrollRun::query()->findOrFail($runId))));
        $viewer = $this->memberWith(['payroll.compensation.sensitive.view'], $school);
        $fact = $this->hrFacts($school)[$rehire['second']];

        $payslip = fn () => $this->actingAs($viewer)->get("/app/payroll/runs/{$runId}/payslips/{$rehire['second']}")->viewData('page')['props']['payslip'];
        $first = $payslip();
        $this->assertSame(['Rehired Person', $fact['employeeNumber'], $fact['employeeId']], [$first['employeeFullName'], $first['employeeNumber'], $first['employeeId']]);

        app(TenantContext::class)->withSchool($school, fn () => DB::table('employees')->where('id', $rehire['employee'])->update(['user_id' => null]));
        $this->assertSame($first, $payslip(), 'an unlinked account changes nothing on the payslip');
    }
}
