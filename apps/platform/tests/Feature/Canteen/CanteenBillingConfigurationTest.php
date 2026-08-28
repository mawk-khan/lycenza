<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Application\CanteenBillingConfigurationService;
use App\Domain\Canteen\Application\Exceptions\CanteenBillingAccountInvalidException;
use App\Domain\Canteen\Application\Exceptions\CanteenBillingNotConfiguredException;
use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- CanteenBillingConfigurationService validation, both at
 * save time (configure()) and at the authoritative pre-fulfillment
 * check (resolveValidated()): missing configuration, cross-School
 * account, inactive account, wrong account type, and identical
 * accounts. Every call is wrapped in TenantContext::withSchool() --
 * CanteenBillingConfigurationService (like Inventory/Hostel's own
 * Application services) relies on the CALLER already having
 * established RLS context, exactly like it would already be
 * established by ResolveSchoolContext middleware for a real HTTP
 * request; a direct unit-level service call in a test must set it up
 * itself.
 */
class CanteenBillingConfigurationTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): CanteenBillingConfigurationService
    {
        return app(CanteenBillingConfigurationService::class);
    }

    private function configure(School $school, string $receivableId, string $revenueId): CanteenBillingConfiguration
    {
        return app(TenantContext::class)->withSchool($school, fn () => $this->service()->configure($school, $receivableId, $revenueId));
    }

    private function resolveValidated(School $school): CanteenBillingConfiguration
    {
        return app(TenantContext::class)->withSchool($school, fn () => $this->service()->resolveValidated($school));
    }

    #[Test]
    public function a_valid_configuration_can_be_saved(): void
    {
        $school = $this->createSchool();
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $config = $this->configure($school, $receivable->id, $revenue->id);

        $this->assertSame($receivable->id, $config->receivable_ledger_account_id);
        $this->assertSame($revenue->id, $config->revenue_ledger_account_id);
        $this->assertSame('INR', $config->currency);
    }

    #[Test]
    public function saving_a_second_time_updates_the_singleton_rather_than_creating_a_second_row(): void
    {
        $school = $this->createSchool();
        $receivable1 = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue1 = $this->createLedgerAccount($school, ['type' => 'income']);
        $receivable2 = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue2 = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->configure($school, $receivable1->id, $revenue1->id);
        $config = $this->configure($school, $receivable2->id, $revenue2->id);

        $this->assertSame($receivable2->id, $config->receivable_ledger_account_id);
        $count = app(TenantContext::class)->withSchool($school, fn () => CanteenBillingConfiguration::query()->count());
        $this->assertSame(1, $count);
    }

    #[Test]
    public function identical_receivable_and_revenue_accounts_are_rejected(): void
    {
        $school = $this->createSchool();
        $account = $this->createLedgerAccount($school, ['type' => 'asset']);

        $this->expectException(CanteenBillingAccountInvalidException::class);
        $this->configure($school, $account->id, $account->id);
    }

    #[Test]
    public function a_receivable_account_from_a_different_school_is_rejected(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $receivable = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(CanteenBillingAccountInvalidException::class);
        $this->configure($school, $receivable->id, $revenue->id);
    }

    #[Test]
    public function an_inactive_account_is_rejected(): void
    {
        $school = $this->createSchool();
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset', 'status' => 'inactive']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(CanteenBillingAccountInvalidException::class);
        $this->configure($school, $receivable->id, $revenue->id);
    }

    #[Test]
    public function a_receivable_account_of_the_wrong_type_is_rejected(): void
    {
        $school = $this->createSchool();
        $receivable = $this->createLedgerAccount($school, ['type' => 'expense']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(CanteenBillingAccountInvalidException::class);
        $this->configure($school, $receivable->id, $revenue->id);
    }

    #[Test]
    public function a_revenue_account_of_the_wrong_type_is_rejected(): void
    {
        $school = $this->createSchool();
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'liability']);

        $this->expectException(CanteenBillingAccountInvalidException::class);
        $this->configure($school, $receivable->id, $revenue->id);
    }

    #[Test]
    public function resolve_validated_throws_when_no_configuration_exists(): void
    {
        $school = $this->createSchool();

        $this->expectException(CanteenBillingNotConfiguredException::class);
        $this->resolveValidated($school);
    }

    #[Test]
    public function resolve_validated_throws_when_a_previously_valid_account_has_since_gone_inactive(): void
    {
        $school = $this->createSchool();
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->configure($school, $receivable->id, $revenue->id);

        app(TenantContext::class)->withSchool($school, fn () => $receivable->update(['status' => 'inactive']));

        $this->expectException(CanteenBillingAccountInvalidException::class);
        $this->resolveValidated($school);
    }

    #[Test]
    public function resolve_validated_succeeds_for_a_valid_configuration(): void
    {
        $school = $this->createSchool();
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->configure($school, $receivable->id, $revenue->id);

        $config = $this->resolveValidated($school);

        $this->assertSame($receivable->id, $config->receivable_ledger_account_id);
    }
}
