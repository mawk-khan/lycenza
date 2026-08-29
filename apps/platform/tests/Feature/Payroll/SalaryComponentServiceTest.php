<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.2 functional tests for `SalaryComponentService`.
 */
class SalaryComponentServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): SalaryComponentService
    {
        return app(SalaryComponentService::class);
    }

    #[Test]
    public function it_creates_an_earning_component_with_no_ledger_account(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();

        $component = app(TenantContext::class)->withSchool(
            $school,
            fn () => $this->service()->create($school, 'BASIC', 'Basic', 'earning', null, $actor),
        );

        $this->assertTrue($component->isEarning());
        $this->assertNull($component->liability_ledger_account_id);
    }

    #[Test]
    public function it_creates_a_deduction_component_with_a_same_school_ledger_account(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $ledgerAccount = $context->withSchool($school, fn () => LedgerAccount::factory()->for($school, 'school')->type('liability')->create());

        $component = $context->withSchool(
            $school,
            fn () => $this->service()->create($school, 'PF', 'Provident Fund', 'deduction', $ledgerAccount->id, $actor),
        );

        $this->assertTrue($component->isDeduction());
        $this->assertSame($ledgerAccount->id, $component->liability_ledger_account_id);
    }

    #[Test]
    public function a_cross_school_ledger_account_reference_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $ledgerAccountInB = $context->withSchool($schoolB, fn () => LedgerAccount::factory()->for($schoolB, 'school')->type('liability')->create());

        $this->expectException(QueryException::class);

        $context->withSchool(
            $schoolA,
            fn () => $this->service()->create($schoolA, 'PF', 'Provident Fund', 'deduction', $ledgerAccountInB->id, $actor),
        );
    }

    #[Test]
    public function it_deactivates_a_component(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $component = $context->withSchool($school, fn () => $this->service()->create($school, 'BASIC', 'Basic', 'earning', null, $actor));
        $deactivated = $context->withSchool($school, fn () => $this->service()->deactivate($component, $actor));

        $this->assertSame('inactive', $deactivated->status);
    }
}
