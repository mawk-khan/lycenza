<?php

namespace Tests\Feature\Postgres;

use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * E21.3A (ADR 0064 §2, §3): the database enforces the period model on its
 * own, for the runtime role and raw SQL: RLS, no deletes, immutable closed
 * periods and baselines, no overlap, no period before a closed one, every
 * entry in exactly one open period, the start month frozen, and the
 * narrow backfill function.
 */
class FinancialPeriodSchemaTest extends TestCase
{
    use CreatesFinancialPeriodFixtures;

    private const TABLES = ['financial_periods', 'financial_period_account_balances', 'financial_period_charge_states'];

    private function setSchool(?string $schoolId): void
    {
        DB::select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    private function assertRejectedBy(string $fragment, callable $op, string $message): void
    {
        try {
            DB::transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage(), $message);
        }
    }

    private function period(string $schoolId, string $startsOn, string $endsOn, string $key, string $status = 'open'): void
    {
        DB::table('financial_periods')->insert([
            'id' => (string) new UuidV7, 'school_id' => $schoolId, 'period_key' => $key, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_tables_have_forced_rls_isolate_schools_and_fail_closed(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace', [$table, 'public']);
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, $table);
        }

        $w = $this->twoYearWorld();
        $this->closePeriod($w, $w['fy2526']);
        $other = $this->createSchool();

        $this->setSchool($w['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
        }
        $this->setSchool($other->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} of another School is invisible");
        }
        $this->setSchool(null);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} is empty without tenant context");
        }
    }

    #[Test]
    public function nothing_is_deleted_and_closed_periods_and_baselines_never_change(): void
    {
        $w = $this->twoYearWorld();
        $this->closePeriod($w, $w['fy2526']);
        $this->setSchool($w['school']->id);

        foreach (self::TABLES as $table) {
            $this->assertRejectedBy('permission denied', fn () => DB::table($table)->where('school_id', $w['school']->id)->delete(), "{$table}: no runtime DELETE");
        }
        foreach (['financial_period_account_balances', 'financial_period_charge_states'] as $table) {
            $this->assertRejectedBy('permission denied', fn () => DB::table($table)->where('school_id', $w['school']->id)->update(['created_at' => now()]), "{$table} is append-only");
        }
        $this->assertRejectedBy('a closed financial period is immutable', fn () => DB::table('financial_periods')->where('id', $w['fy2526']->id)->update(['status' => 'open', 'closed_at' => null, 'close_fingerprint' => null]), 'No reopen');
        $this->assertRejectedBy('boundaries are immutable', fn () => DB::table('financial_periods')->where('id', $w['fy2627']->id)->update(['ends_on' => '2027-06-30']), 'Open boundaries are immutable too');
        $this->assertRejectedBy('only while closing an open period', fn () => DB::table('financial_period_charge_states')->insert([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'financial_period_id' => $w['fy2526']->id, 'charge_id' => $w['c4']->id,
            'currency' => 'INR', 'amount' => '400.00', 'allocated_total' => '0.00', 'live_adjusted_total' => '0.00', 'cancelled' => false,
        ]), 'A closed period gains no baseline');
        $this->assertRejectedBy('shape_check', fn () => DB::table('financial_periods')->where('id', $w['fy2627']->id)->update(['status' => 'closed']), 'Closed needs closed_at and a fingerprint');
    }

    #[Test]
    public function periods_never_overlap_start_open_repeat_idempotently_and_never_precede_a_closed_one(): void
    {
        $w = $this->twoYearWorld();
        $id = $w['school']->id;
        $this->setSchool($id);

        $this->assertRejectedBy('may not overlap', fn () => $this->period($id, '2026-01-01', '2026-12-31', '2026'), 'Overlap');
        $this->assertRejectedBy('created open', fn () => $this->period($id, '2027-04-01', '2028-03-31', '2027-28', 'closed'), 'Created open only');
        $this->assertRejectedBy('no longer match the start month', fn () => $this->period($id, '2027-05-01', '2028-04-30', '2027-28'), 'Boundaries follow the start month');

        // The identical period again is a silent no-op (two first postings racing).
        $this->period($id, '2026-04-01', '2027-03-31', '2026-27');
        $this->assertSame(2, DB::table('financial_periods')->count());

        $this->closePeriod($w, $w['fy2526']);
        $this->setSchool($id);
        $this->assertRejectedBy('no period may open before a closed period', fn () => $this->period($id, '2024-04-01', '2025-03-31', '2024-25'), 'Closed history gains no period');

        $this->assertSame(7, (int) hexdec(substr(str_replace('-', '', $w['fy2526']->id), 12, 1)), 'Period ids are UUIDv7 (ADR 0019).');
    }

    #[Test]
    public function every_entry_lands_in_exactly_one_open_period_by_its_school_local_date(): void
    {
        $w = $this->concessionWorld();
        $other = $this->concessionWorld();

        // 2026-03-31 19:00 UTC is already 2026-04-01 in Asia/Kolkata.
        $this->travelTo(Carbon::parse('2026-03-31 19:00:00', 'UTC'));
        $entry = $this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '1.00');
        $this->travelBack();
        $this->assertSame('2026-27', $this->entryPeriodKey($w['school'], $entry->id));

        $this->setSchool($w['school']->id);
        $this->assertRejectedBy('no_financial_period', fn () => DB::table('journal_entries')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'currency' => 'INR', 'description' => 'raw', 'posted_at' => '2019-07-01 00:00:00',
        ]), 'A raw entry with no period is refused');
        $otherPeriod = $this->inSchool($other['school'], fn () => FinancialPeriod::query()->value('id'));
        $this->setSchool($w['school']->id);
        $this->assertRejectedBy('outside its financial period', fn () => DB::table('journal_entries')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'currency' => 'INR', 'description' => 'raw', 'posted_at' => now(), 'financial_period_id' => $otherPeriod,
        ]), "Another School's period is never usable");
    }

    #[Test]
    public function the_start_month_freezes_once_a_period_exists(): void
    {
        $fresh = $this->createSchool();
        $this->setSchool($fresh->id);
        DB::table('fee_settings')->insert(['id' => (string) new UuidV7, 'school_id' => $fresh->id, 'currency' => 'INR', 'financial_year_start_month' => 7, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fee_settings')->where('school_id', $fresh->id)->update(['financial_year_start_month' => 1]);

        $w = $this->concessionWorld();
        $this->setSchool($w['school']->id);
        $this->assertRejectedBy('cannot change once financial periods exist', fn () => DB::table('fee_settings')->where('school_id', $w['school']->id)->update(['financial_year_start_month' => 1]), 'Frozen once posted');
    }

    #[Test]
    public function the_backfill_function_is_narrow(): void
    {
        $w = $this->concessionWorld();
        $entry = $this->inSchool($w['school'], fn () => DB::table('journal_entries')->value('id'));
        $period = $this->inSchool($w['school'], fn () => FinancialPeriod::query()->value('id'));

        $this->setSchool(null);
        $this->assertRejectedBy('needs the School context', fn () => DB::select('select finance_assign_journal_entry_period(?, ?)', [$entry, $period]), 'Tenant context required');

        $other = $this->createSchool();
        $this->setSchool($other->id);
        $this->assertFalse((bool) DB::selectOne('select finance_assign_journal_entry_period(?, ?) as ok', [$entry, $period])->ok, "Another School's entry is never touched");
        $this->setSchool($w['school']->id);
        $this->assertFalse((bool) DB::selectOne('select finance_assign_journal_entry_period(?, ?) as ok', [$entry, $period])->ok, 'An entry that has a period is never re-assigned');

        $acl = DB::connection('pgsql_admin')->selectOne(
            "select p.prosecdef, array_to_string(p.proconfig, ',') as config,
                    exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0) as public_exec
               from pg_proc p where p.proname = 'finance_assign_journal_entry_period'",
        );
        $this->assertTrue($acl->prosecdef);
        $this->assertStringContainsString('search_path=pg_catalog, pg_temp', $acl->config);
        $this->assertFalse($acl->public_exec);
    }
}
