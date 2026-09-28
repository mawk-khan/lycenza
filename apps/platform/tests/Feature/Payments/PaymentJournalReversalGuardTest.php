<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Application\Exceptions\JournalEntryNotReversibleException;
use App\Domain\Finance\Application\LedgerAdministrationService;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 2, owner
 * decision 2026-09-28): a Payment's settlement journal entry -- manual or
 * provider-derived -- can no longer be reversed through the generic ledger
 * reversal, which left the Payment and its allocations posted against a
 * reversed entry. Charge cancellation and ordinary reversal still work.
 */
class PaymentJournalReversalGuardTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    /**
     * @return array{0: School, 1: User, 2: string, 3: string, 4: object}
     *                                                                    [school, admin, manual journal id, provider journal id, unpaid charge]
     */
    private function world(): array
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $paid = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $unpaid = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $admin = $this->createPaymentRecorder($school);

        $manual = $this->recordManualPayment($school, $admin, $settlement->id, [[$paid, '60.00']], '60.00');
        $provider = $this->recordSettlement($school, $settlement->id, [[$paid, '40.00']], '40.00');

        return [$school, $admin, $this->findPayment($school, $manual->paymentId)->journal_entry_id, $provider->journalEntryId, $unpaid];
    }

    #[Test]
    public function the_ledger_administration_service_refuses_reversing_a_payment_journal_entry(): void
    {
        [$school, $admin, $manualJournal, $providerJournal] = $this->world();

        foreach ([$manualJournal, $providerJournal] as $journalEntryId) {
            try {
                app(LedgerAdministrationService::class)->reverse($school, $journalEntryId, $admin, 'attempt');
                $this->fail('A Payment-owned journal entry must not be reversible directly.');
            } catch (JournalEntryNotReversibleException $e) {
                $this->assertSame(409, $e->getStatusCode());
                $this->assertSame('JOURNAL_ENTRY_NOT_REVERSIBLE', $e->errorCode());
            }
        }

        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->whereNotNull('reversal_of_journal_entry_id')->count()));
    }

    #[Test]
    public function the_json_api_answers_409_journal_entry_not_reversible(): void
    {
        [$school, $admin, $manualJournal] = $this->world();

        $this->withHeader('Authorization', 'Bearer '.$admin->createToken('test-device')->plainTextToken)
            ->postJson("/api/v1/schools/{$school->id}/journal-entries/{$manualJournal}/reverse")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOURNAL_ENTRY_NOT_REVERSIBLE');
    }

    #[Test]
    public function the_browser_reversal_shows_a_reversal_error(): void
    {
        [$school, $admin, $manualJournal] = $this->world();
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");

        $this->post("/app/finance/journal-entries/{$manualJournal}/reverse")
            ->assertRedirect("/app/finance/journal-entries/{$manualJournal}")
            ->assertSessionHasErrors('reversal');
    }

    #[Test]
    public function charge_cancellation_and_ordinary_journal_reversal_are_unaffected(): void
    {
        [$school, $admin, , , $unpaid] = $this->world();

        $cancelled = app(ChargeService::class)->cancel($school, $unpaid->id, $admin);
        $this->assertNotNull($cancelled->cancellationJournalEntryId);

        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income, '10.00');
        $reversal = app(LedgerAdministrationService::class)->reverse($school, $entry->id, $admin);
        $this->assertNotSame($entry->id, $reversal->journalEntryId);
    }
}
