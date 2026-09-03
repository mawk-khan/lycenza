<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.8 -- the /api/v1 Payroll surface: authentication/authorization
 * boundaries, cross-School rejection (IDOR), and a full end-to-end
 * HTTP lifecycle (structure -> compensation -> period -> run ->
 * calculate -> approve -> post -> results -> reverse -> correction).
 * Mirrors `Tests\Feature\Timetable\TimetableApiTest`'s exact pattern.
 * Domain-level invariants (overlap, SoD, concurrency, signed posting
 * directions) are already proven by the Application-layer test
 * suites (9.2-9.7); these tests cover the HTTP boundary only.
 */
class PayrollApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    /**
     * `Auth::forgetGuards()` is required here, not merely defensive:
     * within a single test method, Laravel's TestCase does not tear
     * down the container between simulated `getJson()`/`postJson()`
     * calls, and Sanctum's guard caches its resolved user for the
     * guard instance's lifetime -- a SECOND call authenticating as a
     * genuinely different user (a new Bearer token) would otherwise
     * silently still authorize as the FIRST call's user. Forgetting
     * guards forces fresh token resolution on the next request.
     */
    private function actingWithCapabilities(School $school, array $capabilities)
    {
        Auth::forgetGuards();
        $user = $this->createUserWithCapabilities($school, $capabilities);

        return $this->withHeader('Authorization', 'Bearer '.$this->token($user));
    }

    /**
     * Returns a bearer TOKEN, not a chained TestCase client -- unlike
     * `actingWithCapabilities()`, this is for tests that must
     * interleave several distinct actors' requests. `withHeader()`
     * mutates and returns `$this` (the same TestCase instance), so
     * storing its return value in a variable and reusing that variable
     * LATER, after a DIFFERENT actor's `withHeader()` call has since
     * overwritten the shared `Authorization` header, would silently
     * replay the wrong actor's credentials. `as()` below re-applies a
     * token's header immediately before every single request instead.
     */
    private function authToken(School $school, array $capabilities): string
    {
        Auth::forgetGuards();
        $user = $this->createUserWithCapabilities($school, $capabilities);

        return $this->token($user);
    }

    private function as(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    // --- Guest / unauthenticated -----------------------------------------

    #[Test]
    public function a_guest_is_denied_on_every_payroll_endpoint(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/salary-components")->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/salary-components", [])->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/salary-structures")->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/payroll-periods", [])->assertUnauthorized();
    }

    // --- Capability gating --------------------------------------------------

    #[Test]
    public function listing_salary_components_requires_structures_view(): void
    {
        $school = $this->createSchool();

        $this->actingWithCapabilities($school, [])
            ->getJson("/api/v1/schools/{$school->id}/salary-components")
            ->assertForbidden();

        $this->actingWithCapabilities($school, ['payroll.structures.view'])
            ->getJson("/api/v1/schools/{$school->id}/salary-components")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function creating_a_salary_component_requires_structures_manage_not_view(): void
    {
        $school = $this->createSchool();

        $this->actingWithCapabilities($school, ['payroll.structures.view'])
            ->postJson("/api/v1/schools/{$school->id}/salary-components", [
                'code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning',
            ])
            ->assertForbidden();

        $this->actingWithCapabilities($school, ['payroll.structures.manage'])
            ->postJson("/api/v1/schools/{$school->id}/salary-components", [
                'code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'BASIC');
    }

    #[Test]
    public function an_actor_holding_only_runs_prepare_cannot_approve(): void
    {
        $f = $this->buildCalculatedRun();

        $this->actingWithCapabilities($f['school'], ['payroll.runs.prepare'])
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/approve")
            ->assertForbidden();

        $this->actingWithCapabilities($f['school'], ['payroll.runs.approve'])
            ->withHeader('Idempotency-Key', 'approve-key-001')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    #[Test]
    public function non_sensitive_compensation_listing_never_exposes_amounts(): void
    {
        $f = $this->buildCalculatedRun();

        $response = $this->actingWithCapabilities($f['school'], ['payroll.compensation.view'])
            ->getJson("/api/v1/schools/{$f['school']->id}/employment-records/{$f['employmentRecordId']}/compensation-assignments")
            ->assertOk();

        $response->assertJsonStructure(['data' => [['id', 'employmentRecordId', 'salaryStructureId', 'effectiveFrom']]]);
        $this->assertArrayNotHasKey('amount', $response->json('data.0'));

        $this->actingWithCapabilities($f['school'], ['payroll.compensation.view'])
            ->getJson("/api/v1/schools/{$f['school']->id}/compensation-assignments/{$f['assignmentId']}/values")
            ->assertForbidden();

        $this->actingWithCapabilities($f['school'], ['payroll.compensation.sensitive.view'])
            ->getJson("/api/v1/schools/{$f['school']->id}/compensation-assignments/{$f['assignmentId']}/values")
            ->assertOk()
            ->assertJsonPath('data.0.amount', '50000.00');
    }

    #[Test]
    public function a_cross_school_payroll_run_id_404s_not_leaks(): void
    {
        $f = $this->buildCalculatedRun();
        $otherSchool = $this->createSchool();

        $this->actingWithCapabilities($otherSchool, ['payroll.runs.view'])
            ->getJson("/api/v1/schools/{$otherSchool->id}/payroll-runs/{$f['runId']}")
            ->assertNotFound();
    }

    // --- Payslip rendering (Phase 9.10) --------------------------------------

    #[Test]
    public function getting_a_payslip_requires_sensitive_view_not_runs_view_alone(): void
    {
        $f = $this->buildCalculatedRun();

        $approverToken = $this->authToken($f['school'], ['payroll.runs.approve']);
        $this->as($approverToken)->withHeader('Idempotency-Key', 'payslip-http-approve-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/approve")
            ->assertOk();

        $this->actingWithCapabilities($f['school'], ['payroll.runs.view'])
            ->getJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/payslips/{$f['employmentRecordId']}")
            ->assertForbidden();

        $this->actingWithCapabilities($f['school'], ['payroll.compensation.sensitive.view'])
            ->getJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/payslips/{$f['employmentRecordId']}")
            ->assertOk()
            ->assertJsonPath('data.runStatus', 'approved');
    }

    #[Test]
    public function a_draft_or_calculated_run_payslip_is_a_422_not_a_final_payslip_over_http(): void
    {
        $f = $this->buildCalculatedRun();

        $this->actingWithCapabilities($f['school'], ['payroll.compensation.sensitive.view'])
            ->getJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/payslips/{$f['employmentRecordId']}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYROLL_RUN_NOT_ELIGIBLE_FOR_PAYSLIP');
    }

    #[Test]
    public function a_cross_school_payslip_404s_not_leaks(): void
    {
        $f = $this->buildCalculatedRun();
        $otherSchool = $this->createSchool();

        $this->actingWithCapabilities($otherSchool, ['payroll.compensation.sensitive.view'])
            ->getJson("/api/v1/schools/{$otherSchool->id}/payroll-runs/{$f['runId']}/payslips/{$f['employmentRecordId']}")
            ->assertNotFound();
    }

    // --- Full lifecycle -----------------------------------------------------

    #[Test]
    public function the_full_run_lifecycle_succeeds_over_http_including_posting_reversal_and_correction(): void
    {
        $school = $this->createSchool();
        $adminToken = $this->authToken($school, [
            'payroll.structures.manage', 'payroll.structures.view',
            'payroll.compensation.sensitive.manage', 'payroll.compensation.view',
            'payroll.periods.manage', 'payroll.runs.view', 'payroll.runs.prepare',
            'payroll.accounting.manage',
        ]);

        [$expense, $payable] = app(TenantContext::class)->withSchool($school, fn () => [
            LedgerAccount::factory()->for($school, 'school')->type('expense')->create(),
            LedgerAccount::factory()->for($school, 'school')->type('liability')->create(),
        ]);

        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-accounting-configuration", [
            'salary_expense_ledger_account_id' => $expense->id,
            'salary_payable_ledger_account_id' => $payable->id,
        ])->assertCreated();

        $basic = $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/salary-components", [
            'code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning',
        ])->assertCreated()->json('data.id');

        $structure = $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/salary-structures", [
            'code' => 'GRADE-HTTP', 'name' => 'Grade HTTP',
        ])->assertCreated()->json('data.id');

        $basicSc = $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/salary-structures/{$structure}/components", [
            'salary_component_id' => $basic, 'calculation_type' => 'fixed_amount',
            'base_component_id' => null, 'rate' => null, 'display_order' => 1,
        ])->assertCreated()->json('data.id');

        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/salary-structures/{$structure}/activate")->assertOk();

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);

        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/employment-records/{$employmentRecord->id}/compensation-assignments", [
            'salary_structure_id' => $structure,
            'effective_from' => '2025-01-01',
            'fixed_values' => [['salary_structure_component_id' => $basicSc, 'amount' => '50000.00']],
        ])->assertCreated();

        $period = $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-periods", [
            'period_month' => '2026-09-01',
        ])->assertCreated()->json('data.id');

        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-periods/{$period}/open")->assertOk();

        $run = $this->as($adminToken)->withHeader('Idempotency-Key', 'create-run-key')
            ->postJson("/api/v1/schools/{$school->id}/payroll-periods/{$period}/payroll-runs")
            ->assertCreated()->json('data.id');

        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/calculate")
            ->assertOk()->assertJsonPath('data.transitionedToCalculated', true);

        $approverToken = $this->authToken($school, ['payroll.runs.approve']);
        $this->as($approverToken)->withHeader('Idempotency-Key', 'approve-run-key')
            ->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $posterToken = $this->authToken($school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.compensation.sensitive.view']);
        $this->as($posterToken)->withHeader('Idempotency-Key', 'post-run-key')
            ->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/post")
            ->assertCreated()->assertJsonPath('data.postingKind', 'original');

        $this->as($posterToken)->getJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/results")
            ->assertOk()->assertJsonPath('data.0.netAmount', '50000.00');

        // Phase 9.10: the on-demand payslip endpoint, proven over HTTP
        // through the JSON envelope `PayslipController::present()`
        // actually builds -- never a raw model dump.
        $this->as($posterToken)->getJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/payslips/{$employmentRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.runStatus', 'posted')
            ->assertJsonPath('data.isReversed', false)
            ->assertJsonPath('data.netAmount', '50000.00')
            ->assertJsonPath('data.statutoryDeductionsIncluded', false)
            ->assertJsonMissingPath('data.bankAccountNumber');

        $correctionPeriod = $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-periods", [
            'period_month' => '2026-10-01',
        ])->assertCreated()->json('data.id');
        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-periods/{$correctionPeriod}/open")->assertOk();

        $correction = $this->as($adminToken)->withHeader('Idempotency-Key', 'create-correction-key')
            ->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/correction", [
                'payroll_period_id' => $correctionPeriod,
            ])->assertCreated()->assertJsonPath('data.runKind', 'correction')->json('data.id');

        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$correction}/correction-deltas", [
            'employment_record_id' => $employmentRecord->id,
            'lines' => [['salary_component_id' => $basic, 'amount' => '500.00', 'effect' => 'increase']],
            'reason' => 'basic pay was underpaid',
        ])->assertCreated();

        $this->as($adminToken)->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$correction}/calculate")
            ->assertOk()->assertJsonPath('data.transitionedToCalculated', true);

        // Reversing the ORIGINAL run is still allowed even though a
        // correction now references it -- ADR 0034's deliberately
        // unrestricted reverse direction (Checkpoint 9.5), proven here
        // over HTTP too.
        $this->as($posterToken)->withHeader('Idempotency-Key', 'reverse-run-key')
            ->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/reverse")
            ->assertCreated()->assertJsonPath('data.postingKind', 'reversal');
    }

    /**
     * @return array{school: School, employmentRecordId: string, assignmentId: string, runId: string}
     */
    private function buildCalculatedRun(): array
    {
        $school = $this->createSchool();
        $admin = $this->actingWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.compensation.sensitive.manage',
            'payroll.periods.manage', 'payroll.runs.prepare',
        ]);

        $basic = $admin->postJson("/api/v1/schools/{$school->id}/salary-components", [
            'code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning',
        ])->json('data.id');

        $structure = $admin->postJson("/api/v1/schools/{$school->id}/salary-structures", [
            'code' => 'GRADE-BUILD', 'name' => 'Grade Build',
        ])->json('data.id');

        $basicSc = $admin->postJson("/api/v1/schools/{$school->id}/salary-structures/{$structure}/components", [
            'salary_component_id' => $basic, 'calculation_type' => 'fixed_amount',
            'base_component_id' => null, 'rate' => null, 'display_order' => 1,
        ])->json('data.id');

        $admin->postJson("/api/v1/schools/{$school->id}/salary-structures/{$structure}/activate");

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);

        $assignment = $admin->postJson("/api/v1/schools/{$school->id}/employment-records/{$employmentRecord->id}/compensation-assignments", [
            'salary_structure_id' => $structure,
            'effective_from' => '2025-01-01',
            'fixed_values' => [['salary_structure_component_id' => $basicSc, 'amount' => '50000.00']],
        ])->json('data.id');

        $period = $admin->postJson("/api/v1/schools/{$school->id}/payroll-periods", [
            'period_month' => '2026-09-01',
        ])->json('data.id');
        $admin->postJson("/api/v1/schools/{$school->id}/payroll-periods/{$period}/open");

        $run = $admin->withHeader('Idempotency-Key', 'build-create-run-key')
            ->postJson("/api/v1/schools/{$school->id}/payroll-periods/{$period}/payroll-runs")->json('data.id');
        $admin->postJson("/api/v1/schools/{$school->id}/payroll-runs/{$run}/calculate");

        return ['school' => $school, 'employmentRecordId' => $employmentRecord->id, 'assignmentId' => $assignment, 'runId' => $run];
    }
}
