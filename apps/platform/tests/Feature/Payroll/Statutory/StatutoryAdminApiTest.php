<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6I (Section 3 "API security") -- REQUIRED HTTP contract
 * proofs for the statutory administration API, mirroring
 * `HrEmployeeSensitiveDocumentApiTest`'s exact discipline: real
 * Bearer-token HTTP requests, raw response-body inspection (never
 * Eloquent/service-layer shortcuts) for the sensitive-leak proofs.
 * `Auth::forgetGuards()` between two different-actor calls in the
 * same test method is this codebase's own established fix for
 * Sanctum's guard-level user caching across sub-requests within one
 * test (see `CampusApiTest`'s identical usage) -- not a workaround
 * invented here.
 */
class StatutoryAdminApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    #[Test]
    public function rule_status_requires_view_capability(): void
    {
        $school = $this->createSchool();
        $noCapabilityActor = $this->createUserWithCapabilities($school, []);

        $this->withHeader('Authorization', 'Bearer '.$this->token($noCapabilityActor))
            ->getJson("/api/v1/schools/{$school->id}/payroll-statutory/rule-status")
            ->assertForbidden();

        Auth::forgetGuards();
        $viewerActor = $this->createUserWithCapabilities($school, ['payroll.statutory.view']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($viewerActor))
            ->getJson("/api/v1/schools/{$school->id}/payroll-statutory/rule-status")
            ->assertOk()
            ->assertJsonPath('data.pf.legalReference', 'SCH/PAY/REG/2026-9.6');
    }

    #[Test]
    public function pf_status_allow_deny_and_cross_school_isolation(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employmentRecordA = $this->context()->withSchool($schoolA, fn () => $this->createEmploymentRecord($this->createEmployee($schoolA)));

        $noCapabilityActor = $this->createUserWithCapabilities($schoolA, []);
        $this->withHeader('Authorization', 'Bearer '.$this->token($noCapabilityActor))
            ->postJson("/api/v1/schools/{$schoolA->id}/payroll-statutory/pf-status/{$employmentRecordA->id}", $this->pfPayload())
            ->assertForbidden();

        // Denied mutation produces zero side effect.
        $rowExists = $this->context()->withSchool($schoolA, fn () => EmployeePfStatus::query()->where('employment_record_id', $employmentRecordA->id)->exists());
        $this->assertFalse($rowExists);

        Auth::forgetGuards();
        $manager = $this->createUserWithCapabilities($schoolA, ['payroll.statutory.manage']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($manager))
            ->postJson("/api/v1/schools/{$schoolA->id}/payroll-statutory/pf-status/{$employmentRecordA->id}", $this->pfPayload())
            ->assertCreated()
            ->assertJsonPath('data.hasExistingPfMembership', true)
            ->assertJsonPath('data.hasHigherPensionStatus', false);

        // Cross-School IDOR: a School B actor, even with the
        // capability, must never read/reach School A's row.
        Auth::forgetGuards();
        $schoolBActor = $this->createUserWithCapabilities($schoolB, ['payroll.statutory.view']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($schoolBActor))
            ->getJson("/api/v1/schools/{$schoolB->id}/payroll-statutory/pf-status/{$employmentRecordA->id}")
            ->assertNotFound();

        // No-oracle: a nonexistent employment record under the SAME
        // valid School still returns 404, not a 200/null that would
        // let a caller distinguish "wrong school" from "no data yet".
        Auth::forgetGuards();
        $viewer = $this->createUserWithCapabilities($schoolA, ['payroll.statutory.view']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($viewer))
            ->getJson("/api/v1/schools/{$schoolA->id}/payroll-statutory/pf-status/".Str::uuid())
            ->assertNotFound();
    }

    #[Test]
    public function tax_profile_and_esi_coverage_and_accounting_configuration_require_the_correct_capability(): void
    {
        $school = $this->createSchool();
        $employmentRecord = $this->context()->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));
        $noCapabilityActor = $this->createUserWithCapabilities($school, []);

        $this->withHeader('Authorization', 'Bearer '.$this->token($noCapabilityActor))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/tax-profile/{$employmentRecord->id}", $this->taxProfilePayload())
            ->assertForbidden();

        Auth::forgetGuards();
        $manager = $this->createUserWithCapabilities($school, ['payroll.statutory.manage']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($manager))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/tax-profile/{$employmentRecord->id}", $this->taxProfilePayload())
            ->assertCreated()
            ->assertJsonPath('data.regime', 'new');

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->token($noCapabilityActor))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/esi-coverage/{$employmentRecord->id}", $this->esiPayload())
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->token($manager))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/esi-coverage/{$employmentRecord->id}", $this->esiPayload())
            ->assertCreated()
            ->assertJsonPath('data.isCovered', true);

        Auth::forgetGuards();
        $accountIds = $this->context()->withSchool($school, fn () => $this->statutoryAccountIds($school));
        $this->withHeader('Authorization', 'Bearer '.$this->token($noCapabilityActor))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/accounting-configuration", $accountIds)
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->token($manager))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/accounting-configuration", $accountIds)
            ->assertCreated();
    }

    #[Test]
    public function identifiers_are_masked_by_default_never_leak_raw_without_capability_and_reveal_only_with_it(): void
    {
        $school = $this->createSchool();
        $employmentRecord = $this->context()->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));
        $identifierManager = $this->createUserWithCapabilities($school, ['payroll.statutory.identifiers.manage']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($identifierManager))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/identifiers/{$employmentRecord->id}", [
                'identifier_type' => 'pan',
                'value' => 'ABCDE1234F',
            ])
            ->assertCreated();

        // A store attempt without .identifiers.manage produces zero
        // side effect on a second identifier type.
        Auth::forgetGuards();
        $noCapabilityActor = $this->createUserWithCapabilities($school, []);
        $this->withHeader('Authorization', 'Bearer '.$this->token($noCapabilityActor))
            ->postJson("/api/v1/schools/{$school->id}/payroll-statutory/identifiers/{$employmentRecord->id}", [
                'identifier_type' => 'uan',
                'value' => '100123456789',
            ])
            ->assertForbidden();
        $uanExists = $this->context()->withSchool($school, fn () => EmployeeStatutoryIdentifier::query()->where('employment_record_id', $employmentRecord->id)->where('identifier_type', 'uan')->exists());
        $this->assertFalse($uanExists);

        // list() -- masked form only, viewable with .view alone, raw
        // PAN string never present in the RAW response body.
        Auth::forgetGuards();
        $viewer = $this->createUserWithCapabilities($school, ['payroll.statutory.view']);
        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($viewer))
            ->getJson("/api/v1/schools/{$school->id}/payroll-statutory/identifiers/{$employmentRecord->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ABCDE1234F', $raw, 'The raw PAN must never appear in the masked list response.');
        $this->assertStringContainsString('ABXXXXXX4F', $raw);

        // reveal() -- denied without .identifiers.view; the raw value
        // must not appear anywhere in that denied response either.
        Auth::forgetGuards();
        $deniedReveal = $this->withHeader('Authorization', 'Bearer '.$this->token($viewer))
            ->getJson("/api/v1/schools/{$school->id}/payroll-statutory/identifiers/{$employmentRecord->id}/pan/reveal");
        $deniedReveal->assertForbidden();
        $this->assertStringNotContainsString('ABCDE1234F', $deniedReveal->getContent());

        // reveal() -- allowed and returns the exact raw value with
        // .identifiers.view.
        Auth::forgetGuards();
        $identifierViewer = $this->createUserWithCapabilities($school, ['payroll.statutory.identifiers.view']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($identifierViewer))
            ->getJson("/api/v1/schools/{$school->id}/payroll-statutory/identifiers/{$employmentRecord->id}/pan/reveal")
            ->assertOk()
            ->assertJsonPath('data.value', 'ABCDE1234F');
    }

    #[Test]
    public function statutory_exports_are_denied_without_the_export_capability(): void
    {
        $school = $this->createSchool();
        $run = $this->context()->withSchool($school, function () use ($school) {
            $preparer = $this->createUser();
            $periodService = app(PayrollPeriodService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);

            return app(PayrollRunService::class)->createRun($period, $preparer);
        });

        $noCapabilityActor = $this->createUserWithCapabilities($school, []);
        foreach (['ecr', 'esi-worksheet', 'tds-draft-statement'] as $export) {
            $this->withHeader('Authorization', 'Bearer '.$this->token($noCapabilityActor))
                ->get("/api/v1/schools/{$school->id}/payroll-runs/{$run->id}/statutory-exports/{$export}")
                ->assertForbidden();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pfPayload(): array
    {
        return [
            'has_existing_pf_membership' => true,
            'has_uan' => true,
            'has_approved_higher_wage_contribution' => false,
            'higher_wage_approval_reference' => null,
            'higher_wage_approval_effective_from' => null,
            'is_eps_eligible' => true,
            'has_higher_pension_status' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxProfilePayload(): array
    {
        return [
            'fiscal_year_start' => '2026-04-01',
            'regime' => 'new',
            'regime_switch_policy_reference' => null,
            'previous_employer_income' => '0.00',
            'previous_employer_tds' => '0.00',
            'declared_other_income' => '0.00',
            'declared_deductions' => '0.00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function esiPayload(): array
    {
        return [
            'period_start' => '2026-04-01',
            'period_end' => '2026-09-30',
            'entry_wage' => '18000.00',
            'is_covered' => true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statutoryAccountIds(School $school): array
    {
        $liability = fn () => LedgerAccount::factory()->for($school, 'school')->type('liability')->create()->id;
        $expense = fn () => LedgerAccount::factory()->for($school, 'school')->type('expense')->create()->id;

        return [
            'employee_pf_payable_ledger_account_id' => $liability(),
            'employer_eps_payable_ledger_account_id' => $liability(),
            'employer_epf_payable_ledger_account_id' => $liability(),
            'pf_admin_charge_payable_ledger_account_id' => $liability(),
            'edli_payable_ledger_account_id' => $liability(),
            'esi_payable_ledger_account_id' => $liability(),
            'tds_payable_ledger_account_id' => $liability(),
            'professional_tax_payable_ledger_account_id' => $liability(),
            'lwf_payable_ledger_account_id' => $liability(),
            'employer_pf_contribution_expense_ledger_account_id' => $expense(),
            'pf_admin_charge_expense_ledger_account_id' => $expense(),
            'edli_expense_ledger_account_id' => $expense(),
            'employer_esi_contribution_expense_ledger_account_id' => $expense(),
            'employer_lwf_contribution_expense_ledger_account_id' => $expense(),
        ];
    }
}
