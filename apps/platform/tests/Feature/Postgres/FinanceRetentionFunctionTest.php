<?php

namespace Tests\Feature\Postgres;

use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Feature\Finance\Concerns\CreatesFinanceRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3A2 (E21-D8): `retention_expire_finance_unit` verifies a unit on its
 * own, whatever the caller selects: tenant, closed period, 8-calendar-year
 * floor (real database clock), settled, closed under every reference. The
 * runtime role still has no DELETE on any Finance ledger, and the patched
 * guards open only inside the definer function.
 *
 * E21-RH.6: the function is the retention identity's only, so it is called
 * on `pgsql_retention` (a separate session: committed fixtures).
 */
class FinanceRetentionFunctionTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesFinanceRetentionFixtures;

    private function setSchool(?string $schoolId): void
    {
        foreach (['pgsql', RetentionExpiry::PRIVILEGED_CONNECTION] as $connection) {
            DB::connection($connection)->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
        }
    }

    private function expire(string $schoolId, array $charges, array $entries, bool $dryRun = false): int
    {
        $retention = DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION);

        return (int) $retention->transaction(fn () => $retention->selectOne(
            'SELECT retention_expire_finance_unit(?, ?::uuid[], ?::uuid[], ?, ?) AS n',
            [$schoolId, '{'.implode(',', $charges).'}', '{'.implode(',', $entries).'}', (string) new UuidV7, $dryRun ? 'true' : 'false'],
        )->n);
    }

    private function refused(string $fragment, callable $op, string $message): void
    {
        try {
            $op();
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage(), $message);
        }
    }

    #[Test]
    public function the_runtime_role_cannot_delete_finance_evidence_and_the_function_is_narrowly_granted(): void
    {
        $w = $this->d8World();
        $this->setSchool($w['school']->id);

        foreach (['journal_entries', 'journal_lines', 'charges', 'payments', 'payment_allocations', 'payment_receipts', 'financial_period_expiries', 'financial_period_account_balances'] as $table) {
            $this->refused('permission denied', fn () => DB::transaction(fn () => DB::table($table)->where('school_id', $w['school']->id)->delete()), "{$table}: no runtime DELETE");
        }
        $this->refused('permission denied', fn () => DB::transaction(fn () => DB::table('financial_period_expiries')->insert([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'financial_period_id' => $w['fy1516']->id, 'expired_at' => now(), 'charges' => 0, 'payments' => 0, 'journal_entries' => 0,
        ])), 'only the function records lineage');

        $acl = DB::connection('pgsql_admin')->selectOne(
            "SELECT p.prosecdef, array_to_string(p.proconfig, ',') AS config,
                    exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0) AS public_exec,
                    has_function_privilege('school_os_app', p.oid, 'EXECUTE') AS runtime_exec,
                    has_function_privilege('school_os_retention', p.oid, 'EXECUTE') AS retention_exec
               FROM pg_proc p WHERE p.proname = 'retention_expire_finance_unit'",
        );
        $this->assertTrue($acl->prosecdef);
        $this->assertStringContainsString('search_path=pg_catalog, pg_temp', $acl->config);
        $this->assertFalse($acl->public_exec);
        $this->assertFalse($acl->runtime_exec, 'E21-RH.6: the retention identity only');
        $this->assertTrue($acl->retention_exec);
        $this->refused('permission denied', fn () => DB::transaction(fn () => DB::selectOne(
            'SELECT retention_expire_finance_unit(?, ?::uuid[], ?::uuid[], ?, false) AS n', [$w['school']->id, '{}', '{}', (string) new UuidV7],
        )), 'the runtime role cannot call it');
    }

    #[Test]
    public function tenant_context_is_required_and_another_schools_rows_are_unreachable(): void
    {
        $w = $this->d8World();
        $other = $this->d8World();

        $this->setSchool(null);
        $this->refused('retention_tenant', fn () => $this->expire($w['school']->id, [$w['c1']->id], []), 'no tenant context');
        $this->setSchool($other['school']->id);
        $this->refused('retention_tenant', fn () => $this->expire($w['school']->id, [$w['c1']->id], []), 'another School as context');
        $this->refused('unknown charge', fn () => $this->expire($other['school']->id, [$w['c1']->id], []), "another School's charge");
        $this->refused('retention_floor', fn () => $this->expire($other['school']->id, [], [$w['m1'], $w['m1r']]), "another School's entries are never found");
    }

    #[Test]
    public function the_database_refuses_every_ineligible_selection(): void
    {
        $w = $this->d8World();
        $this->setSchool($w['school']->id);

        $this->refused('retention_floor', fn () => $this->expire($w['school']->id, [], [$w['today_entry']]), 'open period');
        $this->refused('retention_floor', fn () => $this->expire($w['school']->id, [$w['c7']->id], []), 'closed today: too young');
        $this->refused('retention_finance_open_charge', fn () => $this->expire($w['school']->id, [$w['c3']->id], []), 'an unsettled charge');
        $this->refused('retention_finance_dependency', fn () => $this->expire($w['school']->id, [], [$w['payroll_entry']]), 'payroll evidence references it');
        $this->refused('retention_finance_dependency', fn () => $this->expire($w['school']->id, [], [$w['m1']]), 'its reversal is not in the unit');
        $this->refused('retention_floor', fn () => $this->expire($w['school']->id, [$w['c6']->id], []), 'its payment is in a young period');
        $this->refused('retention_floor', fn () => $this->expire($w['school']->id, [], [$w['m2'], $w['m2r']]), 'linked across periods: the young reversal is refused');
        $this->refused('retention_finance_dependency', fn () => $this->expire($w['school']->id, [], [$w['m2']]), 'its later reversal still references it');
        $this->refused('unknown charge', fn () => $this->expire($w['school']->id, [(string) Str::uuid()], []), 'an arbitrary id targets nothing');

        $this->assertTrue($this->rowExists($w, 'charges', $w['c3']->id));
        $this->assertTrue($this->rowExists($w, 'journal_entries', $w['m1']));
    }

    #[Test]
    public function an_eligible_unit_dry_runs_then_expires_whole(): void
    {
        $w = $this->d8World();
        $this->setSchool($w['school']->id);

        $this->assertSame(2, $this->expire($w['school']->id, [], [$w['m1'], $w['m1r']], true));
        $this->assertTrue($this->rowExists($w, 'journal_entries', $w['m1']), 'a dry run deletes nothing');

        $this->setSchool($w['school']->id);
        $this->assertSame(2, $this->expire($w['school']->id, [], [$w['m1'], $w['m1r']]));
        $this->assertFalse($this->rowExists($w, 'journal_entries', $w['m1']));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('journal_lines')->whereIn('journal_entry_id', [$w['m1'], $w['m1r']])->count()));

        $this->setSchool($w['school']->id);
        $entries = $this->expire($w['school']->id, [$w['c1']->id], []);
        $this->assertSame(3, $entries, 'the charge entry and both payment entries');
        $this->assertFalse($this->rowExists($w, 'payments', $w['p1']));
        $this->assertSame(2, $this->inSchool($w['school'], fn () => DB::table('financial_period_expiries')->count()));
    }

    #[Test]
    public function the_patched_guards_still_refuse_the_runtime_role_even_with_the_flag(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $this->executedRun($w);
        $item = $this->inSchool($w['school'], fn () => FeeAssessmentRunItem::query()->firstOrFail());

        $this->setSchool($w['school']->id);
        $this->refused('items are fixed', fn () => DB::transaction(function () use ($item) {
            DB::select("SELECT set_config('app.finance_retention_unit', 'on', true)");
            DB::table('fee_assessment_run_items')->where('id', $item->id)->delete();
        }), 'the flag alone grants nothing: the owner privilege is required too');
        $this->assertFalse((bool) DB::selectOne("SELECT set_config('app.finance_retention_unit', 'on', true) IS NOT NULL AND finance_retention_delete_allowed() AS ok")->ok);
    }
}
