<?php

namespace Tests\Feature\App;

use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6I (Section 4/5) -- the administrative Statutory
 * Payroll Inertia UI. Backend authorization/tenant-safety correctness
 * is already proven by `StatutoryAdminApiTest`; this suite covers the
 * Inertia-specific integration and, per Section 5's explicit
 * requirement, proves raw PAN/UAN/etc. values are structurally ABSENT
 * from the serialized page payload for an unauthorized actor -- never
 * merely masked client-side. Mirrors `PayrollUiTest`'s exact
 * `memberWith()`/`activate()` pattern.
 */
class StatutoryPayrollUiTest extends TestCase
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

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    #[Test]
    public function statutory_index_is_denied_without_view_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith([], $school);

        $this->actingAs($user)->get('/app/payroll/statutory')->assertInertia(fn ($page) => $page
            ->where('can.view', false)
            ->where('ruleStatus', null));
    }

    #[Test]
    public function statutory_index_shows_rule_status_with_view_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith(['payroll.statutory.view'], $school);

        $this->actingAs($user)->get('/app/payroll/statutory')->assertInertia(fn ($page) => $page
            ->where('can.view', true)
            ->where('ruleStatus.pf.legalReference', 'SCH/PAY/REG/2026-9.6'));
    }

    #[Test]
    public function employee_page_is_denied_without_view_capability_and_cross_school_is_not_found(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employmentRecord = $this->context()->withSchool($schoolA, fn () => $this->createEmploymentRecord($this->createEmployee($schoolA)));

        $noCapabilityActor = $this->memberWith([], $schoolA);
        $this->actingAs($noCapabilityActor)
            ->get("/app/payroll/statutory/employees/{$employmentRecord->id}")
            ->assertForbidden();

        $schoolBActor = $this->memberWith(['payroll.statutory.view'], $schoolB);
        $this->actingAs($schoolBActor)
            ->get("/app/payroll/statutory/employees/{$employmentRecord->id}")
            ->assertNotFound();
    }

    #[Test]
    public function the_employee_page_never_serializes_a_raw_statutory_identifier_even_for_a_viewer(): void
    {
        $school = $this->createSchool();
        $employmentRecord = $this->context()->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));
        $this->context()->withSchool($school, fn () => EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $employmentRecord->id,
            'identifier_type' => 'pan',
            'encrypted_value' => 'ABCDE1234F',
            'lookup_hash' => str_repeat('a', 64),
        ]));

        // Even an actor who ALSO holds .identifiers.view must never
        // receive the raw value in the INITIAL page payload -- reveal
        // is a separate, explicit, on-demand fetch only.
        $viewer = $this->memberWith(['payroll.statutory.view', 'payroll.statutory.identifiers.view'], $school);

        $response = $this->actingAs($viewer)->get("/app/payroll/statutory/employees/{$employmentRecord->id}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('identifiers.0.masked', 'ABXXXXXX4F'));

        $raw = $response->getContent();
        $this->assertStringNotContainsString('ABCDE1234F', $raw, 'The raw PAN must never be present in the initial page payload.');
    }

    #[Test]
    public function reveal_endpoint_is_denied_without_identifiers_view_and_succeeds_with_it(): void
    {
        $school = $this->createSchool();
        $employmentRecord = $this->context()->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));
        $this->context()->withSchool($school, fn () => EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $employmentRecord->id,
            'identifier_type' => 'pan',
            'encrypted_value' => 'ABCDE1234F',
            'lookup_hash' => str_repeat('a', 64),
        ]));

        $viewer = $this->memberWith(['payroll.statutory.view'], $school);
        $denied = $this->actingAs($viewer)->getJson("/app/payroll/statutory/employees/{$employmentRecord->id}/identifiers/pan/reveal");
        $denied->assertForbidden();
        $this->assertStringNotContainsString('ABCDE1234F', $denied->getContent());

        $identifierViewer = $this->memberWith(['payroll.statutory.view', 'payroll.statutory.identifiers.view'], $school);
        $this->actingAs($identifierViewer)
            ->getJson("/app/payroll/statutory/employees/{$employmentRecord->id}/identifiers/pan/reveal")
            ->assertOk()
            ->assertJsonPath('data.value', 'ABCDE1234F');
    }

    #[Test]
    public function pf_status_form_is_absent_without_manage_capability_and_the_store_action_is_denied(): void
    {
        $school = $this->createSchool();
        $employmentRecord = $this->context()->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));
        $viewer = $this->memberWith(['payroll.statutory.view'], $school);

        $this->actingAs($viewer)
            ->get("/app/payroll/statutory/employees/{$employmentRecord->id}")
            ->assertInertia(fn ($page) => $page->where('can.manage', false));

        $this->actingAs($viewer)
            ->post("/app/payroll/statutory/employees/{$employmentRecord->id}/pf-status", [
                'has_existing_pf_membership' => true,
                'has_uan' => true,
                'has_approved_higher_wage_contribution' => false,
                'higher_wage_approval_reference' => null,
                'higher_wage_approval_effective_from' => null,
                'is_eps_eligible' => true,
                'has_higher_pension_status' => false,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_manager_can_configure_pf_status_and_it_persists(): void
    {
        $school = $this->createSchool();
        $employmentRecord = $this->context()->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));
        $manager = $this->memberWith(['payroll.statutory.view', 'payroll.statutory.manage'], $school);

        $this->actingAs($manager)
            ->post("/app/payroll/statutory/employees/{$employmentRecord->id}/pf-status", [
                'has_existing_pf_membership' => true,
                'has_uan' => true,
                'has_approved_higher_wage_contribution' => false,
                'higher_wage_approval_reference' => null,
                'higher_wage_approval_effective_from' => null,
                'is_eps_eligible' => true,
                'has_higher_pension_status' => false,
            ])
            ->assertRedirect("/app/payroll/statutory/employees/{$employmentRecord->id}");

        $this->actingAs($manager)
            ->get("/app/payroll/statutory/employees/{$employmentRecord->id}")
            ->assertInertia(fn ($page) => $page->where('pfStatus.hasExistingPfMembership', true));
    }

    #[Test]
    public function accounting_configuration_page_is_denied_without_view_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith([], $school);

        $this->actingAs($user)->get('/app/payroll/statutory/accounting')->assertForbidden();
    }
}
