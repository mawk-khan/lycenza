<?php

namespace Tests\Feature\Finance;

use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException;
use App\Domain\Finance\Application\Exceptions\JournalEntryNotReversibleException;
use App\Domain\Finance\Application\LedgerAdministrationService;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Finance\Concerns\CreatesSubledgerJournalFixtures;
use Tests\TestCase;

/**
 * ORG.8-P1 (ADR 0076 §57, closes ORG.8 G-1): a journal entry whose lifecycle
 * another domain owns -- a Charge, a fee adjustment, a Payment, a Payment
 * correction, or the cancellation entry of a Charge or adjustment -- is
 * never reversed through the generic ledger reversal (service, JSON API,
 * browser), even by a School administrator with a fresh MFA code. Before
 * P1 the generic reversal of a Charge's entry succeeded and left the Charge
 * live against a reversed entry, and its canonical cancellation then
 * refused as "already cancelled". The owning domains' own reversals still
 * work and satisfy the database check; a ledger-native entry (a manual
 * posting) and any reversal the generic path itself created stay reversible.
 * Payroll is covered by PayrollJournalReversalGuardTest.
 */
class SubledgerJournalReversalGuardTest extends TestCase
{
    use CreatesSubledgerJournalFixtures, ProvidesSensitiveActionMfa;

    private function reversals(School $school): int
    {
        return $this->inSchool($school, fn () => JournalEntry::query()->whereNotNull('reversal_of_journal_entry_id')->count());
    }

    /** The deferred database checks fire now, inside the School's context, as they would at COMMIT. */
    private function assertCommitWouldSucceed(School $school): void
    {
        $this->inSchool($school, function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_generic_reversal_refuses_every_entry_another_domain_owns(): void
    {
        $w = $this->ownedJournalWorld();
        $before = $this->reversals($w['school']);

        foreach ($w['entries'] as $owner => $entryId) {
            $this->assertNotNull($entryId, $owner);
            try {
                app(LedgerAdministrationService::class)->reverse($w['school'], $entryId, $w['recorder'], 'attempt');
                $this->fail("A {$owner} journal entry must not be reversible through the generic ledger reversal.");
            } catch (JournalEntryNotReversibleException $e) {
                $this->assertSame(409, $e->getStatusCode(), $owner);
                $this->assertSame('JOURNAL_ENTRY_NOT_REVERSIBLE', $e->errorCode(), $owner);
                $this->assertStringNotContainsString($owner, $e->getMessage(), 'the refusal never names the owning domain');
            }
        }

        $this->assertSame($before, $this->reversals($w['school']), 'no reversal entry is created');
        $this->assertNull($this->inSchool($w['school'], fn () => Charge::query()->findOrFail($w['charge']->id)->cancelled_at), 'the Charge stays live and consistent');
        $this->assertCommitWouldSucceed($w['school']);
    }

    #[Test]
    public function the_canonical_domain_reversals_still_work_and_satisfy_the_database_check(): void
    {
        $w = $this->ownedJournalWorld();
        $this->assertCommitWouldSucceed($w['school']);

        $cancelled = $this->cancelChargeAs($w['school'], $w['charge']->id, $w['recorder'], 'billed in error');
        $this->assertSame($w['entries']['charge'], $this->inSchool($w['school'], fn () => JournalEntry::query()->findOrFail($cancelled->cancellationJournalEntryId)->reversal_of_journal_entry_id));

        $adjustmentId = $this->inSchool($w['school'], fn () => FeeAdjustment::query()->where('journal_entry_id', $w['entries']['fee_adjustment'])->value('id'));
        $this->assertNotNull($this->concessions()->cancelAdjustment($w['school'], $adjustmentId, $w['checker'])->cancellation_journal_entry_id);

        $this->assertCommitWouldSucceed($w['school']);

        $this->expectException(ChargeAlreadyCancelledException::class);
        $this->cancelChargeAs($w['school'], $w['charge']->id, $w['recorder']);
    }

    #[Test]
    public function a_ledger_native_entry_and_a_generic_reversal_stay_reversible(): void
    {
        $w = $this->ownedJournalWorld();
        $cash = $this->createLedgerAccount($w['school'], ['type' => 'asset']);
        $income = $this->createLedgerAccount($w['school'], ['type' => 'income']);
        $manual = $this->postBalancedJournalEntry($w['school'], $cash, $income, '10.00');

        $reversal = app(LedgerAdministrationService::class)->reverse($w['school'], $manual->id, $w['recorder'], 'posted twice');
        $repair = app(LedgerAdministrationService::class)->reverse($w['school'], $reversal->journalEntryId, $w['recorder'], 'reversal was wrong');

        $this->assertSame($reversal->journalEntryId, $this->inSchool($w['school'], fn () => JournalEntry::query()->findOrFail($repair->journalEntryId)->reversal_of_journal_entry_id));
        $this->assertCommitWouldSucceed($w['school']);
    }

    #[Test]
    public function the_json_api_and_the_browser_refuse_after_a_valid_fresh_mfa_code(): void
    {
        $w = $this->ownedJournalWorld();
        $before = $this->reversals($w['school']);

        $token = $this->mfaToken($w['recorder']);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/schools/{$w['school']->id}/journal-entries/{$w['entries']['charge']}/reverse", $this->mfaBody($token))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOURNAL_ENTRY_NOT_REVERSIBLE');

        $this->flushHeaders();
        $this->actingAs($w['recorder'])->post("/app/schools/{$w['school']->id}/activate");
        $this->post("/app/finance/journal-entries/{$w['entries']['fee_adjustment']}/reverse", ['mfa_code' => $this->freshMfaCode($w['recorder'])])
            ->assertRedirect("/app/finance/journal-entries/{$w['entries']['fee_adjustment']}")
            ->assertSessionHasErrors('reversal');

        $this->assertSame($before, $this->reversals($w['school']));
    }

    #[Test]
    public function another_schools_administrator_still_cannot_find_the_entry(): void
    {
        $w = $this->ownedJournalWorld();
        $other = $this->createSchool();
        $outsider = $this->createUserWithCapabilities($other, ['finance.ledger.reverse']);

        $this->expectException(JournalEntryNotFoundException::class);
        app(LedgerAdministrationService::class)->reverse($other, $w['entries']['charge'], $outsider);
    }

    #[Test]
    public function the_owner_kinds_are_exactly_the_database_classification(): void
    {
        $w = $this->ownedJournalWorld();

        foreach ($w['entries'] as $owner => $entryId) {
            $this->assertSame($owner, $this->inSchool($w['school'], fn () => DB::selectOne(
                'select public.journal_entry_subledger_owner(?, ?) as owner', [$entryId, $w['school']->id],
            )->owner));
        }
        $this->assertNull($this->inSchool($w['school'], fn () => DB::selectOne(
            'select public.journal_entry_subledger_owner(?, ?) as owner', [$this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '1.00')->id, $w['school']->id],
        )->owner), 'a manual posting is ledger-native');
    }
}
