<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Exceptions\FinancialPeriodCloseRefusedException;
use App\Domain\Finance\Application\Periods\FinanceRetentionReadiness;
use App\Domain\Finance\Application\Periods\FinancialPeriodBackfillService;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Support\Retention\TenantClosureReadiness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * E21.3A (ADR 0064 §3, §6): entries posted before financial periods existed
 * get their period from the backfill, deterministically, rerunnably, with
 * a dry run, and ambiguous entries stay unmapped and block. Also the
 * verifier's detection of a corrupted baseline.
 *
 * COMMITS its fixtures: simulating a pre-E21.3A entry (a NULL period) or a
 * corrupted baseline needs the migration role, which is a separate session
 * that cannot see this test's uncommitted rows.
 */
class FinancialPeriodBackfillTest extends TestCase
{
    use CreatesFinancialPeriodFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private array $schools = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->purgeCommittedSchools($this->schools);
        parent::tearDown();
    }

    private function world(): array
    {
        $w = $this->twoYearWorld();
        $this->schools[] = $w['school'];

        return $w;
    }

    /** Turns the School's ledger back into its pre-E21.3A shape: no periods. */
    private function forgetPeriods(array $w): void
    {
        DB::connection('pgsql_admin')->transaction(function () use ($w) {
            DB::connection('pgsql_admin')->table('journal_entries')->where('school_id', $w['school']->id)->update(['financial_period_id' => null]);
            DB::connection('pgsql_admin')->table('financial_periods')->where('school_id', $w['school']->id)->delete();
        });
    }

    private function backfill(array $w, bool $dryRun = false)
    {
        return app(FinancialPeriodBackfillService::class)->backfill($w['school'], $dryRun, 3);
    }

    #[Test]
    public function the_backfill_maps_every_entry_to_its_period_with_a_dry_run_and_reruns_cleanly(): void
    {
        $w = $this->world();
        $total = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());
        $this->forgetPeriods($w);
        $periods = app(FinancialPeriodService::class);
        $this->assertSame($total, $periods->unmappedEntryCount($w['school']));

        $dry = $this->backfill($w, true);
        $this->assertSame([$total, 0, 0, 0], [$dry->mapped, $dry->ambiguous, $dry->blocked, $dry->errors]);
        $this->assertSame($total, $periods->unmappedEntryCount($w['school']), 'A dry run writes nothing.');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => FinancialPeriod::query()->count()), 'A dry run creates no period either.');

        $applied = $this->backfill($w);
        $this->assertSame([$total, 0, 0, 0, 0], [$applied->mapped, $applied->ambiguous, $applied->blocked, $applied->errors, $applied->remainingUnmapped]);
        $this->assertSame(['2025-26', '2026-27'], $this->inSchool($w['school'], fn () => FinancialPeriod::query()->orderBy('starts_on')->pluck('period_key')->all()));
        foreach ($this->inSchool($w['school'], fn () => JournalEntry::query()->get()) as $entry) {
            $expected = $entry->posted_at->lt(Carbon::parse('2026-03-31 18:30:00', 'UTC')) ? '2025-26' : '2026-27';
            $this->assertSame($expected, $this->entryPeriodKey($w['school'], $entry->id), 'The School-local posting date decides the period.');
        }

        $again = $this->backfill($w);
        $this->assertSame([0, 0, 0], [$again->mapped, $again->ambiguous, $again->remainingUnmapped]);
        $this->assertSame(1, $this->auditCount($w['school'], 'financial_period.backfill_applied'), 'Only a pass that did something is audited.');

        $this->closePeriod($w, $this->periodByKey($w['school'], '2025-26'));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function ambiguous_entries_stay_unmapped_and_block_closing_and_retention(): void
    {
        $w = $this->world();
        $this->forgetPeriods($w);
        $c4Entry = $this->inSchool($w['school'], fn () => DB::table('charges')->where('id', $w['c4']->id)->value('journal_entry_id'));
        $firstYear = $this->inSchool($w['school'], fn () => JournalEntry::query()->where('posted_at', '<', '2026-01-01')->count());
        $total = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());

        DB::connection('pgsql_admin')->transaction(function () use ($w, $c4Entry) {
            // An audited start-month change after the 2025 postings...
            DB::connection('pgsql_admin')->table('school_audit_events')->insert([
                'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'occurred_at' => '2025-12-01 00:00:00',
                'event_type' => 'fee_settings.receipt_numbering_changed',
                'metadata' => json_encode(['before' => ['prefix' => 'RCPT', 'month' => 1], 'after' => ['prefix' => 'RCPT', 'month' => 4]]),
            ]);
            // ...and a 2026 posting whose own audit event is gone.
            DB::connection('pgsql_admin')->table('school_audit_events')->where('subject_id', $c4Entry)->delete();
        });

        $result = $this->backfill($w);
        $this->assertSame($firstYear + 1, $result->ambiguous);
        $this->assertSame($total - $firstYear - 1, $result->mapped);
        $this->assertSame($firstYear + 1, $result->remainingUnmapped);
        $this->assertNull($this->inSchool($w['school'], fn () => JournalEntry::query()->whereKey($c4Entry)->value('financial_period_id')));

        $this->travelTo(Carbon::parse('2027-04-02 06:00:00', 'UTC'));
        try {
            $this->closePeriod($w, $this->periodByKey($w['school'], '2026-27'));
            $this->fail('Unmapped entries dated inside the year block its close.');
        } catch (FinancialPeriodCloseRefusedException $e) {
            $this->assertSame(['unmapped_journal_entries'], $e->reasons);
        }
        $this->travelBack();

        $readiness = app(FinanceRetentionReadiness::class)->assess($w['school']);
        $this->assertContains('unmapped_journal_entries', $readiness['blockers']);
        $this->assertContains('retention_cutover_not_implemented', $readiness['blockers']);
        $this->assertContains('d8_finance_period_mapping_incomplete', app(TenantClosureReadiness::class)->report($w['school'])['gates']);
    }

    #[Test]
    public function an_entry_dated_inside_a_closed_period_is_blocked_never_forced_and_the_verifier_flags_it(): void
    {
        $w = $this->world();
        $this->closePeriod($w, $w['fy2526']);
        DB::connection('pgsql_admin')->table('journal_entries')->where('id', $w['manual']->id)->update(['financial_period_id' => null]);

        $result = $this->backfill($w);
        $this->assertSame([0, 1, 1], [$result->mapped, $result->blocked, $result->remainingUnmapped]);
        $this->assertNull($this->inSchool($w['school'], fn () => JournalEntry::query()->whereKey($w['manual']->id)->value('financial_period_id')));
        $this->assertNotSame([], $this->verifyBalances($w['school']), 'Closed history with an unmapped entry no longer reconciles, and the verifier says so.');
    }

    #[Test]
    public function the_verifier_detects_a_baseline_that_no_longer_reproduces_the_history(): void
    {
        $w = $this->world();
        $this->closePeriod($w, $w['fy2526']);
        $this->assertSame(0, $this->artisan('platform:finance-balances-verify', ['--school' => $w['school']->id])->run());

        // Only the migration role can alter a baseline (append-only for the
        // runtime role); this simulates corruption to prove detection.
        DB::connection('pgsql_admin')->table('financial_period_charge_states')->where('charge_id', $w['c1']->id)->update(['allocated_total' => '299.00']);
        DB::connection('pgsql_admin')->table('financial_period_account_balances')->where('ledger_account_id', $w['revenue']->id)->update(['credit_total' => DB::raw('credit_total + 1')]);

        $mismatches = $this->verifyBalances($w['school']);
        $this->assertContains("charges:charge:{$w['c1']->id}:allocated", $mismatches);
        $this->assertContains("charges:charge:{$w['c1']->id}:outstanding", $mismatches);
        $this->assertContains("charges:student:{$w['student']->id}:dues", $mismatches);
        $this->assertContains("account:{$w['revenue']->id}:INR", $mismatches);

        $this->artisan('platform:finance-balances-verify', ['--school' => $w['school']->id])
            ->expectsOutputToContain('verification=failed')
            ->assertFailed();
    }

    #[Test]
    public function the_commands_report_counts_only(): void
    {
        $w = $this->world();
        $total = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());
        $this->forgetPeriods($w);

        $this->artisan('platform:finance-periods-backfill', ['--school' => $w['school']->id, '--dry-run' => true])
            ->expectsOutputToContain("[dry-run] school={$w['school']->id} mapped={$total} ambiguous=0 blocked=0 error=0")
            ->assertSuccessful();
        $this->artisan('platform:finance-periods-backfill', ['--school' => $w['school']->id])
            ->expectsOutputToContain("[applied] school={$w['school']->id} mapped={$total}")
            ->assertSuccessful();
        $this->artisan('platform:finance-balances-verify', ['--school' => $w['school']->id])
            ->expectsOutputToContain('verification=passed mismatches=0 closed_through=none unmapped_entries=0 blockers=no_closed_financial_period,retention_cutover_not_implemented')
            ->assertSuccessful();
        $this->artisan('platform:finance-periods-backfill', ['--school' => (string) Str::uuid()])->assertFailed();
    }
}
