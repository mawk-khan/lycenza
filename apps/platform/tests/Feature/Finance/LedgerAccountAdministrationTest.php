<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Exceptions\DuplicateLedgerAccountCodeException;
use App\Domain\Finance\Application\Exceptions\InvalidLedgerAccountException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\LedgerAccountAdministrationService;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * FEE.1 (ADR 0062 §6, K1): minimal ledger-account administration --
 * create and activate/deactivate only, `finance.accounts.manage`, audited,
 * never deleted, and never a type change once posted to.
 */
class LedgerAccountAdministrationTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): LedgerAccountAdministrationService
    {
        return app(LedgerAccountAdministrationService::class);
    }

    #[Test]
    public function a_manager_creates_an_active_inr_account_and_it_is_audited(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.accounts.manage']);

        $account = $this->service()->create($school, ['code' => ' ar-fees ', 'name' => 'Fees Receivable', 'type' => 'asset'], $actor);

        $this->assertSame('AR-FEES', $account->code);
        $this->assertSame('asset', $account->type);
        $this->assertSame('INR', $account->currency);
        $this->assertSame('active', $account->status);
        $this->assertFalse($account->isSystem);

        $audit = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'ledger_account.created')->sole());
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertEquals(['ledgerAccountId' => $account->ledgerAccountId, 'code' => 'AR-FEES', 'type' => 'asset'], $audit->metadata);
    }

    #[Test]
    public function creating_requires_finance_accounts_manage_even_with_ledger_view(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUserWithCapabilities($school, ['finance.ledger.view', 'finance.ledger.post']);

        $this->expectException(AuthorizationException::class);
        $this->service()->create($school, ['code' => 'X1', 'name' => 'X', 'type' => 'asset'], $viewer);
    }

    #[Test]
    public function a_capability_in_another_school_does_not_authorize(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $actor = $this->createUserWithCapabilities($other, ['finance.accounts.manage']);

        $this->expectException(AuthorizationException::class);
        $this->service()->create($school, ['code' => 'X1', 'name' => 'X', 'type' => 'asset'], $actor);
    }

    #[Test]
    public function a_case_variant_duplicate_code_is_a_typed_conflict(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.accounts.manage']);
        $this->service()->create($school, ['code' => 'CASH', 'name' => 'Cash', 'type' => 'asset'], $actor);

        $this->expectException(DuplicateLedgerAccountCodeException::class);
        $this->service()->create($school, ['code' => 'cash', 'name' => 'Cash again', 'type' => 'asset'], $actor);
    }

    #[Test]
    public function invalid_type_and_code_are_rejected_before_any_write(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.accounts.manage']);

        foreach ([['code' => 'OK1', 'type' => 'revenue'], ['code' => '-BAD', 'type' => 'asset'], ['code' => str_repeat('A', 33), 'type' => 'asset']] as $input) {
            try {
                $this->service()->create($school, [...$input, 'name' => 'N'], $actor);
                $this->fail('Expected InvalidLedgerAccountException for '.json_encode($input));
            } catch (InvalidLedgerAccountException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => LedgerAccount::query()->count()));
    }

    #[Test]
    public function status_changes_are_audited_and_repeat_changes_are_silent_no_ops(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.accounts.manage']);
        $account = $this->service()->create($school, ['code' => 'BANK', 'name' => 'Bank', 'type' => 'asset'], $actor);

        $this->assertSame('inactive', $this->service()->changeStatus($school, $account->ledgerAccountId, 'inactive', $actor)->status);
        $this->assertSame('inactive', $this->service()->changeStatus($school, $account->ledgerAccountId, 'inactive', $actor)->status);
        $this->assertSame('active', $this->service()->changeStatus($school, $account->ledgerAccountId, 'active', $actor)->status);

        $events = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'ledger_account.status_changed')->orderBy('occurred_at')->orderBy('id')->get());
        $this->assertCount(2, $events, 'The repeated deactivation must not write a second audit event.');
        $this->assertEquals(['from' => 'active', 'to' => 'inactive'], array_intersect_key($events[0]->metadata, ['from' => 1, 'to' => 1]));
    }

    #[Test]
    public function a_status_change_for_another_schools_account_is_not_found(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.accounts.manage']);
        $foreign = $this->createLedgerAccount($other, ['type' => 'asset']);

        $this->expectException(LedgerAccountNotFoundException::class);
        $this->service()->changeStatus($school, $foreign->id, 'inactive', $actor);
    }

    #[Test]
    public function an_unposted_account_type_can_change_at_the_database_but_a_posted_one_cannot(): void
    {
        $school = $this->createSchool();
        $asset = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $spare = $this->createLedgerAccount($school, ['type' => 'expense']);

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $this->assertSame(1, DB::table('ledger_accounts')->where('id', $spare->id)->update(['type' => 'liability']));

        $this->postBalancedJournalEntry($school, $asset, $income);
        // withSchool() inside the posting restores the previous (empty) GUC.
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        try {
            DB::transaction(fn () => DB::table('ledger_accounts')->where('id', $asset->id)->update(['type' => 'expense']));
            $this->fail('A posted account type change must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable after first posting', $e->getMessage());
        }

        $this->assertSame(1, DB::table('ledger_accounts')->where('id', $asset->id)->update(['name' => 'Renamed is fine']));
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_ledger_account(): void
    {
        $school = $this->createSchool();
        $account = $this->createLedgerAccount($school, ['type' => 'asset']);
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        try {
            DB::transaction(fn () => DB::table('ledger_accounts')->where('id', $account->id)->delete());
            $this->fail('DELETE on ledger_accounts must be refused for the runtime role.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function the_service_offers_no_delete_or_type_change_path(): void
    {
        $methods = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(LedgerAccountAdministrationService::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertEqualsCanonicalizing(['__construct', 'create', 'changeStatus'], $methods);
    }
}
