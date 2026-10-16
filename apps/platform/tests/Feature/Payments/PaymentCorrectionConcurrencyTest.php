<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Application\PaymentApplicationReader;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * UAT-C3 (ADR 0031 amendment §3): payment corrections under real
 * concurrency -- two genuinely separate OS processes, committed state. The
 * advisory locks (School + key, then the Payment) serialize them and the
 * deferred balance check backs the result.
 */
class PaymentCorrectionConcurrencyTest extends TestCase
{
    use CreatesReceiptFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            DB::connection('pgsql_admin')->table('domain_event_outbox')->where('school_id', $this->school->id)->delete();
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    /** @return list<string> the two processes' outputs */
    private function race(array $a, array $b): array
    {
        $script = __DIR__.'/../../Support/correct-payment.php';
        $processes = [new Process(['php', $script, ...$a]), new Process(['php', $script, ...$b])];
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }

        return array_map(fn (Process $p) => $p->getOutput().$p->getErrorOutput(), $processes);
    }

    #[Test]
    public function two_identical_concurrent_submissions_record_one_correction_and_the_other_replays_it(): void
    {
        $w = $this->concessionWorld();
        $this->school = $w['school'];
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Second charge');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $args = [$w['school']->id, $w['recorder']->id, $paymentId, PaymentCorrection::REALLOCATION, (string) Str::uuid(), $w['charge']->id, $b->id, '500.00'];

        $outputs = $this->race($args, $args);

        sort($outputs);
        $this->assertStringStartsWith('corrected:', $outputs[0], implode(' | ', $outputs));
        $this->assertStringStartsWith('replayed:', $outputs[1], implode(' | ', $outputs));
        $this->assertSame(substr($outputs[0], 10), substr($outputs[1], 9), 'The replay returns the one recorded correction.');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
        $this->assertSame([$b->id => '500.00'], $this->inSchool($w['school'], fn () => app(PaymentApplicationReader::class)->forPayment($w['school'], $paymentId)));
    }

    #[Test]
    public function two_concurrent_reallocations_of_the_same_money_never_move_more_than_the_payment_has(): void
    {
        $w = $this->concessionWorld();
        $this->school = $w['school'];
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Second charge');
        $c = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Third charge');
        $paymentId = $this->pay($w, '500.00')->paymentId;

        $outputs = $this->race(
            [$w['school']->id, $w['recorder']->id, $paymentId, PaymentCorrection::REALLOCATION, (string) Str::uuid(), $w['charge']->id, $b->id, '400.00'],
            [$w['school']->id, $w['recorder']->id, $paymentId, PaymentCorrection::REALLOCATION, (string) Str::uuid(), $w['charge']->id, $c->id, '400.00'],
        );

        $this->assertCount(1, array_filter($outputs, fn ($o) => str_starts_with($o, 'corrected:')), implode(' | ', $outputs));
        $this->assertCount(1, array_filter($outputs, fn ($o) => str_starts_with($o, 'rejected:')), implode(' | ', $outputs));
        $standing = $this->inSchool($w['school'], fn () => app(PaymentApplicationReader::class)->forPayment($w['school'], $paymentId));
        $this->assertSame('500.00', array_reduce($standing, fn (string $sum, string $amount) => bcadd($sum, $amount, 2), '0.00'), 'The payment still stands for exactly its own amount.');
        $this->assertSame('100.00', $standing[$w['charge']->id]);
    }

    #[Test]
    public function a_reversal_racing_a_reallocation_leaves_one_consistent_outcome(): void
    {
        $w = $this->concessionWorld();
        $this->school = $w['school'];
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Second charge');
        $paymentId = $this->pay($w, '500.00')->paymentId;

        $outputs = $this->race(
            [$w['school']->id, $w['recorder']->id, $paymentId, PaymentCorrection::REVERSAL, (string) Str::uuid()],
            [$w['school']->id, $w['recorder']->id, $paymentId, PaymentCorrection::REALLOCATION, (string) Str::uuid(), $w['charge']->id, $b->id, '500.00'],
        );

        $reversed = str_starts_with($outputs[0], 'corrected:');
        $this->assertTrue($reversed, 'The reversal always succeeds (before or after the move): '.implode(' | ', $outputs));
        $this->assertSame([], $this->inSchool($w['school'], fn () => app(PaymentApplicationReader::class)->forPayment($w['school'], $paymentId)), 'Nothing of a reversed payment stands anywhere.');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->where('kind', PaymentCorrection::REVERSAL)->count()));
    }
}
