<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Auth\Mfa\MfaChallengeService;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof for the Phase 0H.4D-P1 replay-
 * security correction: two genuinely separate OS processes race to
 * verify the SAME valid TOTP code for the SAME factor against real
 * PostgreSQL -- never two sequential calls inside one PHP process,
 * which would never exercise MfaChallengeService::verifyTotp()'s
 * `SELECT ... FOR UPDATE` row lock. The closure audit proved a
 * pre-correction, lock-free verifyTotp() lets both processes read the
 * same stale floor and both succeed; this test is the regression
 * guard for that exact defect. Mirrors MfaRecoveryCodeConcurrencyTest/
 * MfaFactorConcurrencyTest's pattern.
 */
class MfaTotpReplayConcurrencyTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?User $user = null;

    protected function tearDown(): void
    {
        if ($this->user !== null) {
            $this->user->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_verifications_of_the_same_valid_totp_code_leave_exactly_one_winner(): void
    {
        $this->user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();

        $factor = UserMfaFactor::create([
            'user_id' => $this->user->id,
            'type' => 'totp',
            'secret_encrypted' => $secret,
            'status' => 'active',
            'confirmed_at' => now(),
        ]);

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $script = __DIR__.'/../../../Support/verify-totp.php';

        $processA = new Process(['php', $script, $factor->id, $code]);
        $processB = new Process(['php', $script, $factor->id, $code]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $accepted = array_filter($outputs, fn ($o) => $o === 'accepted:true');
        $rejected = array_filter($outputs, fn ($o) => $o === 'accepted:false');

        $this->assertCount(1, $accepted, 'Exactly one concurrent TOTP verification must be accepted. Outputs: '.implode(' | ', $outputs));
        $this->assertCount(1, $rejected, 'The loser must be rejected as a replay. Outputs: '.implode(' | ', $outputs));

        $expectedStep = app(Google2FA::class)->getTimestamp();
        $factor->refresh();
        $this->assertEqualsWithDelta($expectedStep, $factor->last_used_totp_step, 1, 'The claimed step must be recorded exactly once, not overwritten by a second winner.');

        $stillUsable = app(MfaChallengeService::class)->verifyTotp($factor, $code);
        $this->assertFalse($stillUsable, 'No assurance may be independently established a third time from the same TOTP step.');
    }
}
