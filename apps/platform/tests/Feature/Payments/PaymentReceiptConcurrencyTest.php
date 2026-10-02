<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\Exceptions\ReceiptNumberingLockedException;
use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Payments\Domain\ReceiptNumbering;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentReceipt;
use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * FEE.4 (ADR 0062 §12 proof 7, §17.2): real two-process races against real
 * PostgreSQL with forced, verified overlap (the contender is observed
 * blocked on the holder's uncommitted receipt counter before the holder
 * finishes). Never SQLite, never a sequential simulation.
 *
 * 1. Two concurrent settlements in one series get consecutive, unique
 *    numbers.
 * 2. A settlement that rolls back after taking the counter consumes no
 *    number: the waiting contender takes 000001 and the next takes 000002.
 * 3. Two concurrent backfills issue each legacy receipt exactly once.
 * 4. FEE closure remediation: a School's first receipt and a receipt
 *    numbering change serialize on the `fees.settings:{school}` advisory
 *    lock (issuer SHARED, settings mutation EXCLUSIVE), for both the prefix
 *    and the financial-year start month and in both orders. Exactly one
 *    serial outcome: a settings change that commits first is what the
 *    waiting issuer uses; a first receipt that commits first freezes the
 *    setting, and the waiting change is refused.
 */
class PaymentReceiptConcurrencyTest extends TestCase
{
    use CreatesReceiptFixtures, ForcesConcurrentOverlap;

    protected $connectionsToTransact = [];

    private ?School $school = null;

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
    }

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        // Committed fixture roles would leak Finance capabilities into later
        // registry tests (the FeeAssessmentConcurrencyTest precedent).
        DB::connection('pgsql_admin')->table('roles')
            ->where('key', 'like', 'test.capability_grant.%')
            ->where('created_at', '>=', $this->startedAt)
            ->delete();

        parent::tearDown();
    }

    private function world(): array
    {
        $w = $this->concessionWorld();
        $this->school = $w['school'];
        $w['charge2'] = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '1000.00');

        return $w;
    }

    private function script(string $name): string
    {
        return __DIR__."/../../Support/{$name}.php";
    }

    /** @return list<string> */
    private function record(array $w, $charge, string $amount): array
    {
        return ['php', $this->script('race-manual-payment'), 'record', $w['school']->id, $w['recorder']->id, $w['settlement']->id, (string) Str::uuid(), $amount, "{$charge->id}:{$amount}"];
    }

    /** @return list<string> */
    private function numbers(array $w): array
    {
        return $this->inSchool($w['school'], fn () => PaymentReceipt::query()->orderBy('sequence_value')->pluck('receipt_number')->all());
    }

    #[Test]
    public function concurrent_settlements_receive_consecutive_unique_numbers(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->record($w, $w['charge'], '10.00'), $this->record($w, $w['charge2'], '20.00'));

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertStringStartsWith('recorded:', $contender);
        $holderReceipt = $this->receiptOf($w, substr($holder, strlen('recorded:')));
        $contenderReceipt = $this->receiptOf($w, substr($contender, strlen('recorded:')));
        $this->assertSame(1, $holderReceipt->sequence_value, 'The holder took the counter first.');
        $this->assertSame(2, $contenderReceipt->sequence_value, 'The contender waited on the counter lock, then took the next value.');
        $this->assertSame($holderReceipt->series_key, $contenderReceipt->series_key);
    }

    #[Test]
    public function a_rolled_back_issuer_consumes_no_number(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('race-payment-receipt'), 'record-rollback', $w['school']->id, $w['recorder']->id, $w['settlement']->id, $w['charge']->id, '10.00'],
            $this->record($w, $w['charge2'], '20.00'),
        );

        $this->assertStringStartsWith('rolled_back:', $holder);
        $this->assertStringStartsWith('recorded:', $contender);
        $this->assertNull($this->inSchool($w['school'], fn () => Payment::query()->find(substr($holder, strlen('rolled_back:')))), 'The failed settlement left nothing.');
        $this->assertSame(1, $this->receiptOf($w, substr($contender, strlen('recorded:')))->sequence_value, 'The contender received 000001: no committed gap.');

        $next = $this->pay($w, '5.00', $this->today($w), $w['charge2']);
        $this->assertSame(2, $this->receiptOf($w, $next->paymentId)->sequence_value);
        $this->assertCount(2, array_unique($this->numbers($w)));
    }

    #[Test]
    public function concurrent_backfills_issue_each_receipt_exactly_once(): void
    {
        $w = $this->world();
        foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $day) {
            $this->legacyPayment($w, '10.00', $day);
        }

        $command = ['php', $this->script('race-payment-receipt'), 'backfill', $w['school']->id];
        [$holder, $contender] = $this->raceWithHeldHolder($command, $command);

        $this->assertSame('issued:3/skipped:0', $holder);
        $this->assertSame('issued:0/skipped:3', $contender, 'The second run waited on the series lock, then found every receipt issued.');
        $this->assertSame(['RCPT/2026-27/000001', 'RCPT/2026-27/000002', 'RCPT/2026-27/000003'], $this->numbers($w));
    }

    /** @return list<string> */
    private function setNumbering(array $w, string $prefix, int $month): array
    {
        return ['php', $this->script('race-payment-receipt'), 'set-numbering', $w['school']->id, $w['actor']->id, $prefix, (string) $month];
    }

    /** @return list<string> */
    private function recordOn(array $w, string $on): array
    {
        return ['php', $this->script('race-payment-receipt'), 'record-on', $w['school']->id, $w['recorder']->id, $w['settlement']->id, $w['charge']->id, '10.00', $on];
    }

    private function numberingOf(array $w): array
    {
        $numbering = app(FeeSettingsService::class)->receiptNumbering($w['school']);

        return [$numbering->prefix, $numbering->financialYearStartMonth];
    }

    #[Test]
    public function a_prefix_change_that_commits_first_is_used_by_the_waiting_first_receipt(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->setNumbering($w, 'NEWPX', 4), $this->record($w, $w['charge'], '10.00'));

        $this->assertSame('numbering:NEWPX:4', $holder);
        $this->assertStringStartsWith('recorded:', $contender, 'The first receipt waited on the settings lock, then issued.');
        $receipt = $this->receiptOf($w, substr($contender, strlen('recorded:')));
        $this->assertStringStartsWith('NEWPX/', $receipt->receipt_number, 'The waiting issuer used the settings committed before it.');
        $this->assertSame(['NEWPX', 4], $this->numberingOf($w));
    }

    #[Test]
    public function a_first_receipt_that_commits_first_freezes_the_prefix_and_the_waiting_change_is_refused(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->record($w, $w['charge'], '10.00'), $this->setNumbering($w, 'NEWPX', 4));

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertStringStartsWith('rejected:'.ReceiptNumberingLockedException::class, $contender, 'The change waited on the settings lock, then saw the committed series and was refused.');
        $this->assertStringStartsWith('RCPT/', $this->receiptOf($w, substr($holder, strlen('recorded:')))->receipt_number);
        $this->assertSame(['RCPT', 4], $this->numberingOf($w), 'The prefix did not change under a series that already has receipts.');
    }

    #[Test]
    public function a_start_month_change_is_refused_once_anything_is_posted_even_before_any_receipt(): void
    {
        // E21.3A (ADR 0064): the start month defines the financial periods,
        // so it freezes at the School's first posting, not its first receipt.
        // The race between a first posting and a month change is proven in
        // Tests\Feature\Finance\FinancialPeriodConcurrencyTest.
        $w = $this->world();

        try {
            app(FeeSettingsService::class)->setReceiptNumbering($w['school'], 'RCPT', 1, $w['actor']);
            $this->fail('The start month must not change once the ledger has postings.');
        } catch (ReceiptNumberingLockedException $e) {
            $this->assertStringContainsString('posted to the ledger', $e->getMessage());
        }

        $this->assertSame(['RCPT', 4], $this->numberingOf($w));
    }

    #[Test]
    public function a_first_ever_receipt_that_commits_first_freezes_the_start_month_and_the_waiting_change_is_refused(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->recordOn($w, '2026-02-15'), $this->setNumbering($w, 'RCPT', 1));

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertStringStartsWith('rejected:'.ReceiptNumberingLockedException::class, $contender, 'The change waited on the settings lock, then saw the committed receipt and was refused.');
        $receipt = $this->receiptOf($w, substr($holder, strlen('recorded:')));
        $this->assertSame('RCPT/2025-26/000001', $receipt->receipt_number, 'Issued with the April start in force when it committed.');
        $this->assertSame(['RCPT', 4], $this->numberingOf($w), 'The start month did not change once a receipt existed.');
        $this->assertSame($receipt->series_key, ReceiptNumbering::seriesKey(
            CarbonImmutable::parse($this->inSchool($w['school'], fn () => Payment::query()->findOrFail($receipt->payment_id))->settled_at->format('Y-m-d H:i:s'), 'UTC'),
            $w['school']->timezone,
            4,
        ), 'The stored series still matches the frozen start month.');
    }
}
