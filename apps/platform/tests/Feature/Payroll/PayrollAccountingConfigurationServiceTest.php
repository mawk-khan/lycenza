<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\Exceptions\PayrollAccountingAccountInvalidException;
use App\Domain\Payroll\Application\Exceptions\PayrollAccountingNotConfiguredException;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.5 -- functional tests for `PayrollAccountingConfigurationService`
 * (ADR 0032 "Deduction accounting"), mirroring
 * `CanteenBillingConfigurationServiceTest`'s equivalent coverage shape.
 */
class PayrollAccountingConfigurationServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): PayrollAccountingConfigurationService
    {
        return app(PayrollAccountingConfigurationService::class);
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function makeLedgerAccount(School $school, string $type, bool $active = true): LedgerAccount
    {
        return $this->context()->withSchool($school, function () use ($school, $type, $active) {
            return LedgerAccount::factory()->for($school, 'school')->type($type)->when(! $active, fn ($f) => $f->inactive())->create();
        });
    }

    #[Test]
    public function it_configures_valid_expense_and_liability_accounts(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $expense = $this->makeLedgerAccount($school, 'expense');
        $payable = $this->makeLedgerAccount($school, 'liability');

        $config = $this->service()->configure($school, $expense->id, $payable->id, $actor);

        $this->assertSame($expense->id, $config->salary_expense_ledger_account_id);
        $this->assertSame($payable->id, $config->salary_payable_ledger_account_id);
        $this->assertSame('INR', $config->currency);
    }

    #[Test]
    public function it_rejects_a_missing_ledger_account(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $payable = $this->makeLedgerAccount($school, 'liability');

        $this->expectException(PayrollAccountingAccountInvalidException::class);

        $this->service()->configure($school, (string) Str::uuid(), $payable->id, $actor);
    }

    #[Test]
    public function it_rejects_a_ledger_account_from_a_different_school(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $actor = $this->createUser();
        $expense = $this->makeLedgerAccount($otherSchool, 'expense');
        $payable = $this->makeLedgerAccount($school, 'liability');

        $this->expectException(PayrollAccountingAccountInvalidException::class);

        $this->service()->configure($school, $expense->id, $payable->id, $actor);
    }

    #[Test]
    public function it_rejects_an_inactive_ledger_account(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $expense = $this->makeLedgerAccount($school, 'expense', active: false);
        $payable = $this->makeLedgerAccount($school, 'liability');

        $this->expectException(PayrollAccountingAccountInvalidException::class);

        $this->service()->configure($school, $expense->id, $payable->id, $actor);
    }

    #[Test]
    public function it_rejects_a_salary_expense_account_of_the_wrong_type(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $wrongType = $this->makeLedgerAccount($school, 'asset');
        $payable = $this->makeLedgerAccount($school, 'liability');

        $this->expectException(PayrollAccountingAccountInvalidException::class);

        $this->service()->configure($school, $wrongType->id, $payable->id, $actor);
    }

    #[Test]
    public function it_rejects_a_salary_payable_account_of_the_wrong_type(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $expense = $this->makeLedgerAccount($school, 'expense');
        $wrongType = $this->makeLedgerAccount($school, 'income');

        $this->expectException(PayrollAccountingAccountInvalidException::class);

        $this->service()->configure($school, $expense->id, $wrongType->id, $actor);
    }

    #[Test]
    public function resolve_validated_fails_when_never_configured(): void
    {
        $school = $this->createSchool();

        $this->expectException(PayrollAccountingNotConfiguredException::class);

        $this->service()->resolveValidated($school);
    }

    #[Test]
    public function resolve_validated_fails_once_a_previously_valid_account_becomes_inactive(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $expense = $this->makeLedgerAccount($school, 'expense');
        $payable = $this->makeLedgerAccount($school, 'liability');
        $this->service()->configure($school, $expense->id, $payable->id, $actor);

        $this->context()->withSchool($school, fn () => $expense->update(['status' => 'inactive']));

        $this->expectException(PayrollAccountingAccountInvalidException::class);

        $this->service()->resolveValidated($school);
    }

    #[Test]
    public function configuring_twice_updates_the_same_row(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $expense = $this->makeLedgerAccount($school, 'expense');
        $payable = $this->makeLedgerAccount($school, 'liability');
        $first = $this->service()->configure($school, $expense->id, $payable->id, $actor);

        $newExpense = $this->makeLedgerAccount($school, 'expense');
        $second = $this->service()->configure($school, $newExpense->id, $payable->id, $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($newExpense->id, $second->salary_expense_ledger_account_id);
    }

    #[Test]
    public function configuration_is_audited(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $expense = $this->makeLedgerAccount($school, 'expense');
        $payable = $this->makeLedgerAccount($school, 'liability');

        $config = $this->service()->configure($school, $expense->id, $payable->id, $actor);

        $event = $this->context()->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.accounting_configuration.saved')->where('subject_id', $config->id)->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($actor->id, $event->actor_user_id);
    }
}
