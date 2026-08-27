<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException;
use App\Domain\Finance\Application\JournalEntryResult;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerAdministrationService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Models\DomainEventOutbox;
use App\Models\SchoolAuditEvent;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.3 -- `LedgerAdministrationService` is the authorized
 * administrative entry point wrapping the trusted `LedgerService` core
 * (docs/modules/FINANCE.md "0G.3 as-built", "Authorization
 * architecture"). Proves the full section 50/51 authorization test
 * matrix: correct capability succeeds, wrong/missing capability is
 * denied before any mutation, another School's grant never authorizes
 * this School, and a denied operation causes zero new ledger/audit/
 * outbox rows.
 */
class LedgerAdministrationServiceTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): LedgerAdministrationService
    {
        return app(LedgerAdministrationService::class);
    }

    private function postData(string $debitAccountId, string $creditAccountId): PostJournalEntryData
    {
        $money = Money::of('100.00', 'INR');

        return new PostJournalEntryData(
            currency: 'INR',
            description: 'Authorized posting test',
            lines: [
                new JournalLineData($debitAccountId, JournalSide::Debit, $money),
                new JournalLineData($creditAccountId, JournalSide::Credit, $money),
            ],
        );
    }

    // --- post() ---------------------------------------------------

    #[Test]
    public function a_member_with_post_capability_may_post(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.post']);

        $result = $this->service()->post($school, $this->postData($cash->id, $revenue->id), $actor);

        $this->assertInstanceOf(JournalEntryResult::class, $result);
        $this->assertNotInstanceOf(JournalEntry::class, $result);
        $this->assertSame('INR', $result->currency);
        $this->assertSame(2, $result->lineCount);
    }

    #[Test]
    public function a_view_only_member_may_not_post(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.view']);

        $this->expectException(AuthorizationException::class);

        $this->service()->post($school, $this->postData($cash->id, $revenue->id), $actor);
    }

    #[Test]
    public function a_non_member_may_not_post(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUser();

        $this->expectException(AuthorizationException::class);

        $this->service()->post($school, $this->postData($cash->id, $revenue->id), $actor);
    }

    #[Test]
    public function post_capability_granted_only_in_another_school_does_not_authorize_this_one(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $actor = $this->createUserWithCapabilities($otherSchool, ['finance.ledger.post']);
        $this->createMembership($actor, $school);

        $this->expectException(AuthorizationException::class);

        $this->service()->post($school, $this->postData($cash->id, $revenue->id), $actor);
    }

    #[Test]
    public function a_denied_post_creates_no_journal_entry_no_line_no_audit_and_no_outbox(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, []);

        try {
            $this->service()->post($school, $this->postData($cash->id, $revenue->id), $actor);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'journal_entry.posted')->count());
        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.posted.v1')->count());
    }

    // --- reverse() --------------------------------------------------

    #[Test]
    public function a_member_with_reverse_capability_may_reverse(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.reverse']);

        $result = $this->service()->reverse($school, $entry->id, $actor);

        $this->assertInstanceOf(JournalEntryResult::class, $result);
        $this->assertNotInstanceOf(JournalEntry::class, $result);
        $this->assertSame($entry->id, $result->reversalOfJournalEntryId);
    }

    #[Test]
    public function a_view_only_member_may_not_reverse(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.view']);

        $this->expectException(AuthorizationException::class);

        $this->service()->reverse($school, $entry->id, $actor);
    }

    #[Test]
    public function a_post_only_member_may_not_reverse(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.post']);

        $this->expectException(AuthorizationException::class);

        $this->service()->reverse($school, $entry->id, $actor);
    }

    #[Test]
    public function a_non_member_may_not_reverse(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUser();

        $this->expectException(AuthorizationException::class);

        $this->service()->reverse($school, $entry->id, $actor);
    }

    #[Test]
    public function reverse_capability_granted_only_in_another_school_does_not_authorize_this_one(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);

        $actor = $this->createUserWithCapabilities($otherSchool, ['finance.ledger.reverse']);
        $this->createMembership($actor, $school);

        $this->expectException(AuthorizationException::class);

        $this->service()->reverse($school, $entry->id, $actor);
    }

    #[Test]
    public function a_denied_reversal_leaves_the_original_untouched_and_creates_no_reversal_audit_or_outbox(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $revenue);
        $actor = $this->createUserWithCapabilities($school, []);

        try {
            $this->service()->reverse($school, $entry->id, $actor);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        app(TenantContext::class)->withSchool($school, function () use ($entry) {
            $this->assertSame(1, JournalEntry::query()->count(), 'Only the original entry may exist -- no reversal was created.');
            $this->assertFalse($entry->fresh()->reversedBy()->exists());
        });
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'journal_entry.reversed')->count());
        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.reversed.v1')->count());
    }

    #[Test]
    public function reversing_a_nonexistent_journal_entry_id_is_not_found(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.reverse']);

        $this->expectException(JournalEntryNotFoundException::class);

        $this->service()->reverse($school, (string) Str::uuid(), $actor);
    }

    #[Test]
    public function reversing_another_schools_journal_entry_id_is_not_found_not_forbidden(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $cash = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $entryInB = $this->postBalancedJournalEntry($schoolB, $cash, $revenue);

        $actor = $this->createUserWithCapabilities($schoolA, ['finance.ledger.reverse']);

        $this->expectException(JournalEntryNotFoundException::class);

        $this->service()->reverse($schoolA, $entryInB->id, $actor);
    }
}
