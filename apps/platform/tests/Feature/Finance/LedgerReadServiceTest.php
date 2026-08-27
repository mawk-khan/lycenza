<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException;
use App\Domain\Finance\Application\JournalEntryQuery;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerReadService;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\SchoolAuditEvent;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.3 -- `LedgerReadService` disclosure-boundary, pagination,
 * filter, cross-School privacy, and query-count proofs. Mirrors
 * `Tests\Feature\HR\HrDirectoryAuthorizationTest` /
 * `EmployeeDirectoryService`'s test shape for the equivalent Finance
 * surface.
 */
class LedgerReadServiceTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): LedgerReadService
    {
        return app(LedgerReadService::class);
    }

    private function queryCountFor(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // --- listAccounts() ----------------------------------------------

    #[Test]
    public function view_capability_allows_listing_accounts(): void
    {
        $school = $this->createSchool();
        $account = $this->createLedgerAccount($school);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.view']);

        $result = $this->service()->listAccounts($school, $actor);

        $this->assertCount(1, $result);
        $this->assertSame($account->id, $result->first()->ledgerAccountId);
    }

    #[Test]
    public function missing_view_capability_denies_listing_accounts(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school);
        $actor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        $this->service()->listAccounts($school, $actor);
    }

    #[Test]
    public function non_member_is_denied_listing_accounts(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school);
        $actor = $this->createUser();

        $this->expectException(AuthorizationException::class);

        $this->service()->listAccounts($school, $actor);
    }

    #[Test]
    public function view_capability_granted_only_in_another_school_does_not_authorize_this_one(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $this->createLedgerAccount($school);

        $actor = $this->createUserWithCapabilities($otherSchool, ['finance.ledger.view']);
        $this->createMembership($actor, $school);

        $this->expectException(AuthorizationException::class);

        $this->service()->listAccounts($school, $actor);
    }

    #[Test]
    public function account_listing_excludes_another_schools_accounts_and_exposes_only_safe_fields(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $account = $this->createLedgerAccount($school, [
            'code' => 'cash01', 'name' => 'Cash', 'type' => 'asset', 'is_system' => true, 'status' => 'active',
        ]);
        $this->createLedgerAccount($otherSchool);
        $actor = $this->fullFinanceActor($school);

        $result = $this->service()->listAccounts($school, $actor);

        $this->assertCount(1, $result);
        $summary = $result->first();
        $this->assertSame($account->id, $summary->ledgerAccountId);
        $this->assertSame('CASH01', $summary->code);
        $this->assertSame('Cash', $summary->name);
        $this->assertSame('asset', $summary->type);
        $this->assertSame('INR', $summary->currency);
        $this->assertTrue($summary->isSystem);
        $this->assertSame('active', $summary->status);
        $this->assertNotNull($summary->createdAt);
        $this->assertNotNull($summary->updatedAt);
    }

    #[Test]
    public function account_listing_is_ordered_by_normalized_code(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school, ['code' => 'zzz01']);
        $this->createLedgerAccount($school, ['code' => 'aaa01']);
        $this->createLedgerAccount($school, ['code' => 'mmm01']);
        $actor = $this->fullFinanceActor($school);

        $codes = $this->service()->listAccounts($school, $actor)->map(fn ($a) => $a->code)->all();

        $this->assertSame(['AAA01', 'MMM01', 'ZZZ01'], $codes);
    }

    #[Test]
    public function account_listing_includes_both_active_and_inactive_status_preserved(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school, ['code' => 'act01', 'status' => 'active']);
        $this->createLedgerAccount($school, ['code' => 'ina01', 'status' => 'inactive']);
        $actor = $this->fullFinanceActor($school);

        $statuses = $this->service()->listAccounts($school, $actor)->pluck('status')->all();

        $this->assertEqualsCanonicalizing(['active', 'inactive'], $statuses);
    }

    // --- listJournalEntries() -----------------------------------------

    #[Test]
    public function view_capability_allows_listing_journal_entries(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.view']);

        $paginator = $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor);

        $this->assertCount(1, $paginator->items());
        $this->assertSame($entry->id, $paginator->items()[0]->journalEntryId);
        $this->assertSame(2, $paginator->items()[0]->lineCount);
        $this->assertNull($paginator->items()[0]->reversalOfJournalEntryId);
        $this->assertNull($paginator->items()[0]->reversedByJournalEntryId);
    }

    #[Test]
    public function missing_view_capability_denies_listing_journal_entries_and_reveals_no_count(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->postBalancedJournalEntry($school, $cash, $revenue);
        $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, []);

        try {
            $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertStringNotContainsString('2', $e->getMessage());
        }
    }

    #[Test]
    public function non_member_is_denied_listing_journal_entries(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();

        $this->expectException(AuthorizationException::class);

        $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor);
    }

    #[Test]
    public function view_capability_granted_only_in_another_school_does_not_authorize_journal_listing(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $actor = $this->createUserWithCapabilities($otherSchool, ['finance.ledger.view']);
        $this->createMembership($actor, $school);

        $this->expectException(AuthorizationException::class);

        $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor);
    }

    #[Test]
    public function journal_listing_excludes_another_schools_entries(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $cashOther = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $revenueOther = $this->createLedgerAccount($otherSchool, ['type' => 'income']);
        $this->postBalancedJournalEntry($otherSchool, $cashOther, $revenueOther);
        $actor = $this->fullFinanceActor($school);

        $paginator = $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor);

        $this->assertCount(0, $paginator->items());
        $this->assertSame(0, $paginator->total());
    }

    #[Test]
    public function journal_listing_reflects_reversal_relationship_in_both_directions(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $reversal = app(LedgerService::class)->reverse($original);
        $actor = $this->fullFinanceActor($school);

        $items = $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor)->items();
        $byId = collect($items)->keyBy('journalEntryId');

        $this->assertSame($reversal->journalEntryId, $byId->get($original->id)->reversedByJournalEntryId);
        $this->assertNull($byId->get($original->id)->reversalOfJournalEntryId);
        $this->assertSame($original->id, $byId->get($reversal->journalEntryId)->reversalOfJournalEntryId);
        $this->assertNull($byId->get($reversal->journalEntryId)->reversedByJournalEntryId);
    }

    #[Test]
    public function journal_listing_pagination_is_bounded_stable_and_covers_the_last_page(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $created = [];
        for ($i = 0; $i < 5; $i++) {
            $created[] = $this->postBalancedJournalEntry($school, $cash, $revenue)->id;
        }
        $actor = $this->fullFinanceActor($school);

        $page1 = $this->service()->listJournalEntries($school, new JournalEntryQuery(perPage: 2, page: 1), $actor);
        $this->assertCount(2, $page1->items());
        $this->assertSame(5, $page1->total());

        $page3 = $this->service()->listJournalEntries($school, new JournalEntryQuery(perPage: 2, page: 3), $actor);
        $this->assertCount(1, $page3->items());

        $page4 = $this->service()->listJournalEntries($school, new JournalEntryQuery(perPage: 2, page: 4), $actor);
        $this->assertCount(0, $page4->items());

        // Ordering matches an independent PHP-side sort by (posted_at
        // DESC, id DESC) against the real persisted rows -- proving
        // determinism whether or not any two entries share an identical
        // posted_at second-precision timestamp.
        $allItems = $this->service()->listJournalEntries($school, new JournalEntryQuery(perPage: 100), $actor)->items();
        $expectedOrder = collect($allItems)
            ->sortByDesc(fn ($e) => $e->postedAt->format('Y-m-d H:i:s').$e->journalEntryId)
            ->pluck('journalEntryId')
            ->values()
            ->all();
        $actualOrder = collect($allItems)->pluck('journalEntryId')->all();

        $this->assertSame($expectedOrder, $actualOrder);
        $this->assertEqualsCanonicalizing($created, $actualOrder);
    }

    #[Test]
    public function journal_listing_perpage_is_clamped_to_the_maximum(): void
    {
        $query = new JournalEntryQuery(perPage: 99999);

        $this->assertSame(LedgerReadService::MAX_PER_PAGE, $query->perPage);
    }

    #[Test]
    public function journal_listing_filters_by_ledger_account_at_sql_level(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $bank = $this->createLedgerAccount($school, ['type' => 'asset']);
        $entryCash = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $this->postBalancedJournalEntry($school, $bank, $revenue);
        $actor = $this->fullFinanceActor($school);

        $result = $this->service()->listJournalEntries($school, new JournalEntryQuery(ledgerAccountId: $cash->id), $actor);

        $this->assertCount(1, $result->items());
        $this->assertSame($entryCash->id, $result->items()[0]->journalEntryId);
    }

    #[Test]
    public function journal_listing_filters_by_another_schools_account_id_return_empty_not_an_error(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->postBalancedJournalEntry($school, $cash, $revenue);
        $foreignAccount = $this->createLedgerAccount($otherSchool);
        $actor = $this->fullFinanceActor($school);

        $result = $this->service()->listJournalEntries($school, new JournalEntryQuery(ledgerAccountId: $foreignAccount->id), $actor);

        $this->assertCount(0, $result->items());
    }

    #[Test]
    public function journal_listing_filters_by_reversed_only(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $reversal = app(LedgerService::class)->reverse($original);
        $actor = $this->fullFinanceActor($school);

        $reversalsOnly = $this->service()->listJournalEntries($school, new JournalEntryQuery(reversedOnly: true), $actor);
        $originalsOnly = $this->service()->listJournalEntries($school, new JournalEntryQuery(reversedOnly: false), $actor);

        $this->assertSame([$reversal->journalEntryId], collect($reversalsOnly->items())->pluck('journalEntryId')->all());
        $this->assertSame([$original->id], collect($originalsOnly->items())->pluck('journalEntryId')->all());
    }

    #[Test]
    public function journal_listing_filters_by_description_search(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->fullFinanceActor($school);

        app(LedgerService::class)->post($school, new PostJournalEntryData(
            currency: 'INR',
            description: 'Rent payment for August',
            lines: [
                new JournalLineData($cash->id, JournalSide::Credit, Money::of('50.00', 'INR')),
                new JournalLineData($revenue->id, JournalSide::Debit, Money::of('50.00', 'INR')),
            ],
        ));
        $this->postBalancedJournalEntry($school, $cash, $revenue);

        $result = $this->service()->listJournalEntries($school, new JournalEntryQuery(search: 'rent'), $actor);

        $this->assertCount(1, $result->items());
        $this->assertSame('Rent payment for August', $result->items()[0]->description);
    }

    #[Test]
    public function journal_listing_filters_by_posted_date_range(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->fullFinanceActor($school);

        $futureOnly = $this->service()->listJournalEntries(
            $school,
            new JournalEntryQuery(postedFrom: now()->addDay()->toDateTimeString()),
            $actor,
        );
        $includesNow = $this->service()->listJournalEntries(
            $school,
            new JournalEntryQuery(postedFrom: now()->subDay()->toDateTimeString(), postedTo: now()->addDay()->toDateTimeString()),
            $actor,
        );

        $this->assertCount(0, $futureOnly->items());
        $this->assertCount(1, $includesNow->items());
        $this->assertSame($entry->id, $includesNow->items()[0]->journalEntryId);
    }

    #[Test]
    public function journal_listing_query_count_does_not_grow_per_row(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->fullFinanceActor($school);

        for ($i = 0; $i < 10; $i++) {
            $this->postBalancedJournalEntry($school, $cash, $revenue);
        }
        $smallCount = $this->queryCountFor(fn () => $this->service()->listJournalEntries($school, new JournalEntryQuery(perPage: 100), $actor));

        for ($i = 0; $i < 40; $i++) {
            $this->postBalancedJournalEntry($school, $cash, $revenue);
        }
        $largeCount = $this->queryCountFor(fn () => $this->service()->listJournalEntries($school, new JournalEntryQuery(perPage: 100), $actor));

        $this->assertLessThanOrEqual(2, $largeCount - $smallCount, 'Query count grew by '.($largeCount - $smallCount).' between 10 and 50 entries -- suggests a per-row query.');
    }

    // --- getJournalEntryDetail() ---------------------------------------

    #[Test]
    public function view_capability_allows_journal_detail(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['code' => 'cash01', 'name' => 'Cash', 'type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['code' => 'rev01', 'name' => 'Revenue', 'type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue, '250.00');
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.view']);

        $detail = $this->service()->getJournalEntryDetail($school, $entry->id, $actor);

        $this->assertSame($entry->id, $detail->journalEntryId);
        $this->assertSame('INR', $detail->currency);
        $this->assertNull($detail->reversalOfJournalEntryId);
        $this->assertNull($detail->reversedByJournalEntryId);
        $this->assertCount(2, $detail->lines);

        $debitLine = collect($detail->lines)->firstWhere('side', JournalSide::Debit);
        $creditLine = collect($detail->lines)->firstWhere('side', JournalSide::Credit);

        $this->assertSame($cash->id, $debitLine->ledgerAccountId);
        $this->assertSame('CASH01', $debitLine->accountCode);
        $this->assertSame('Cash', $debitLine->accountName);
        $this->assertSame('250.00', $debitLine->amount->amount());
        $this->assertSame('INR', $debitLine->amount->currency());

        $this->assertSame($revenue->id, $creditLine->ledgerAccountId);
        $this->assertSame('REV01', $creditLine->accountCode);
        $this->assertSame('Revenue', $creditLine->accountName);
    }

    #[Test]
    public function missing_view_capability_denies_journal_detail(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        $this->service()->getJournalEntryDetail($school, $entry->id, $actor);
    }

    #[Test]
    public function non_member_is_denied_journal_detail(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUser();

        $this->expectException(AuthorizationException::class);

        $this->service()->getJournalEntryDetail($school, $entry->id, $actor);
    }

    #[Test]
    public function a_nonexistent_journal_entry_id_is_not_found(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullFinanceActor($school);

        $this->expectException(JournalEntryNotFoundException::class);

        $this->service()->getJournalEntryDetail($school, (string) Str::uuid(), $actor);
    }

    #[Test]
    public function another_schools_journal_entry_id_produces_the_identical_not_found_outcome(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $cash = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $entryInB = $this->postBalancedJournalEntry($schoolB, $cash, $revenue);
        $actor = $this->fullFinanceActor($schoolA);

        try {
            $this->service()->getJournalEntryDetail($schoolA, $entryInB->id, $actor);
            $this->fail('Expected a JournalEntryNotFoundException.');
        } catch (JournalEntryNotFoundException $nonexistent) {
            // Compare against the message a genuinely nonexistent id
            // produces -- must be identical in shape, distinguishing
            // only by the id itself (never revealing "exists elsewhere").
            $genuineException = new JournalEntryNotFoundException($entryInB->id);
            $this->assertSame($genuineException->getMessage(), $nonexistent->getMessage());
        }
    }

    #[Test]
    public function journal_detail_reflects_reversal_relationship_in_both_directions(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $reversal = app(LedgerService::class)->reverse($original);
        $actor = $this->fullFinanceActor($school);

        $originalDetail = $this->service()->getJournalEntryDetail($school, $original->id, $actor);
        $reversalDetail = $this->service()->getJournalEntryDetail($school, $reversal->journalEntryId, $actor);

        $this->assertSame($reversal->journalEntryId, $originalDetail->reversedByJournalEntryId);
        $this->assertNull($originalDetail->reversalOfJournalEntryId);
        $this->assertSame($original->id, $reversalDetail->reversalOfJournalEntryId);
        $this->assertNull($reversalDetail->reversedByJournalEntryId);
    }

    #[Test]
    public function journal_detail_query_count_does_not_grow_per_line(): void
    {
        $school = $this->createSchool();
        $accounts = [];
        for ($i = 0; $i < 21; $i++) {
            $accounts[] = $this->createLedgerAccount($school, ['type' => $i === 0 ? 'asset' : 'income']);
        }
        $actor = $this->fullFinanceActor($school);

        $smallLines = [
            new JournalLineData($accounts[0]->id, JournalSide::Debit, Money::of('2.00', 'INR')),
            new JournalLineData($accounts[1]->id, JournalSide::Credit, Money::of('2.00', 'INR')),
        ];
        $smallEntry = app(LedgerService::class)->post($school, new PostJournalEntryData('INR', 'small', $smallLines));

        $largeLines = [];
        $totalCredit = Money::of('0', 'INR');
        for ($i = 1; $i < 21; $i++) {
            $amount = Money::of('1.00', 'INR');
            $largeLines[] = new JournalLineData($accounts[$i]->id, JournalSide::Credit, $amount);
            $totalCredit = $totalCredit->add($amount);
        }
        $largeLines[] = new JournalLineData($accounts[0]->id, JournalSide::Debit, $totalCredit);
        $largeEntry = app(LedgerService::class)->post($school, new PostJournalEntryData('INR', 'large', $largeLines));

        // Warm the capability cache once before measuring -- the FIRST
        // authorization check for a given (actor, school) pair is a
        // cache miss (several extra queries), which would otherwise
        // swamp the per-line signal this test is isolating. Both
        // measurements below run with an identical, already-warm cache
        // state, so any remaining delta reflects line count alone.
        $this->service()->getJournalEntryDetail($school, $smallEntry->journalEntryId, $actor);

        $smallQueryCount = $this->queryCountFor(fn () => $this->service()->getJournalEntryDetail($school, $smallEntry->journalEntryId, $actor));
        $largeQueryCount = $this->queryCountFor(fn () => $this->service()->getJournalEntryDetail($school, $largeEntry->journalEntryId, $actor));

        $this->assertSame($smallQueryCount, $largeQueryCount, 'Detail query count must not grow with line count (2 vs 20 lines) -- suggests a per-line account lookup.');
    }

    #[Test]
    public function listing_accounts_and_journal_entries_never_return_a_raw_eloquent_model(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->fullFinanceActor($school);

        $accounts = $this->service()->listAccounts($school, $actor);
        $this->assertNotInstanceOf(LedgerAccount::class, $accounts->first());

        $entries = $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor);
        $this->assertNotInstanceOf(JournalEntry::class, $entries->items()[0]);

        $detail = $this->service()->getJournalEntryDetail($school, $entry->id, $actor);
        $this->assertNotInstanceOf(JournalEntry::class, $detail);
        $this->assertNotInstanceOf(JournalLine::class, $detail->lines[0]);
    }

    // --- Read audit (Highly Sensitive tier, FINANCE.md) ----------------

    #[Test]
    public function a_successful_account_listing_is_audited_exactly_once(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school);
        $actor = $this->fullFinanceActor($school);

        $this->service()->listAccounts($school, $actor);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'ledger_account.list_viewed')->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_successful_journal_listing_is_audited_exactly_once(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->fullFinanceActor($school);

        $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'journal_entry.list_viewed')->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_successful_journal_detail_view_is_audited_exactly_once(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->fullFinanceActor($school);

        $this->service()->getJournalEntryDetail($school, $entry->id, $actor);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'journal_entry.detail_viewed')->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_denied_read_creates_no_audit_event(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, []);

        foreach (['listAccounts', 'listJournalEntries', 'getJournalEntryDetail'] as $method) {
            try {
                match ($method) {
                    'listAccounts' => $this->service()->listAccounts($school, $actor),
                    'listJournalEntries' => $this->service()->listJournalEntries($school, new JournalEntryQuery, $actor),
                    'getJournalEntryDetail' => $this->service()->getJournalEntryDetail($school, $entry->id, $actor),
                };
                $this->fail("Expected an AuthorizationException from {$method}.");
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'ledger_account.list_viewed')->count());
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'journal_entry.list_viewed')->count());
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'journal_entry.detail_viewed')->count());
    }
}
