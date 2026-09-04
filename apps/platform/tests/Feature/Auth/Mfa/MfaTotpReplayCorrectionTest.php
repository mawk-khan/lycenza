<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Auth\Mfa\MfaEnrollmentService;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 replay-security correction regression suite. Covers
 * the two closure BLOCKERs' non-concurrency dimensions -- the real
 * two-OS-process proof lives in MfaTotpReplayConcurrencyTest, since
 * PHPUnit's own transaction-per-test isolation cannot exercise a
 * genuine cross-process row lock.
 */
class MfaTotpReplayCorrectionTest extends TestCase
{
    use CreatesMfaFixtures;

    #[Test]
    public function the_totp_code_used_to_confirm_enrollment_cannot_be_replayed_at_the_next_login(): void
    {
        // Closure blocker regression: confirm() used to prime the
        // replay floor with a meaningless value (a cast boolean),
        // leaving the enrollment code itself replayable at the very
        // next verification. It must not be, even while the code
        // remains inside its normal acceptance window.
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $code = $this->currentTotpCodeFor($secret);

        app(MfaEnrollmentService::class)->begin($user);
        // begin() generates its own secret; force the pending factor to
        // the known secret so we can compute a matching code above --
        // via the Eloquent model (respecting the `encrypted` cast), not
        // a raw query-builder update (which would bypass encryption
        // entirely and write plaintext into an `encrypted`-cast column).
        $pending = $user->mfaFactors()->where('status', 'pending')->first();
        $pending->secret_encrypted = $secret;
        $pending->save();

        app(MfaEnrollmentService::class)->confirm($user, $code);

        $factor = $user->fresh()->mfaFactors()->where('status', 'active')->first();

        $this->assertFalse(
            app(MfaChallengeService::class)->verifyTotp($factor, $code),
            'The exact TOTP code used to confirm enrollment must be rejected as a replay on the very next verification attempt.',
        );
    }

    #[Test]
    public function accepted_current_step_then_same_step_replayed_then_an_older_step_then_a_newer_step(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $factor = $this->enrollActiveMfaFactor($user, $secret);
        $challenge = app(MfaChallengeService::class);
        $google2fa = app(Google2FA::class);

        $currentStep = $google2fa->getTimestamp();
        $currentCode = $google2fa->oathTotp($secret, $currentStep);

        // 1. Accepted current step.
        $this->assertTrue($challenge->verifyTotp($factor, $currentCode));
        $factor->refresh();
        $this->assertSame($currentStep, $factor->last_used_totp_step);

        // 2. Replay of the SAME step is rejected.
        $this->assertFalse($challenge->verifyTotp($factor, $currentCode));

        // 3. An OLDER step (structurally still within the ±1 clock-skew
        //    window, i.e. NOT rejected merely for being "too old" to
        //    exist in the window) is rejected because it is at/below
        //    the replay floor established in step 1.
        $olderStepCode = $google2fa->oathTotp($secret, $currentStep - 1);
        $this->assertFalse($challenge->verifyTotp($factor, $olderStepCode));

        // 4. A genuinely NEWER, not-yet-claimed step within the
        //    configured window is accepted, and advances the floor.
        $newerStepCode = $google2fa->oathTotp($secret, $currentStep + 1);
        $this->assertTrue($challenge->verifyTotp($factor, $newerStepCode));
        $factor->refresh();
        $this->assertSame($currentStep + 1, $factor->last_used_totp_step);
    }

    #[Test]
    public function last_used_totp_step_is_a_period_counter_and_last_used_at_is_a_real_wall_clock_time(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $factor = $this->enrollActiveMfaFactor($user, $secret);
        $google2fa = app(Google2FA::class);

        $beforeVerification = now();
        $code = $this->currentTotpCodeFor($secret);
        $this->assertTrue(app(MfaChallengeService::class)->verifyTotp($factor, $code));
        $factor->refresh();

        // last_used_totp_step is google2fa's own period counter --
        // deliberately compared against the library's own
        // getTimestamp(), never against a Carbon/epoch value.
        $this->assertIsInt($factor->last_used_totp_step);
        $this->assertEqualsWithDelta($google2fa->getTimestamp(), $factor->last_used_totp_step, 1);

        // last_used_at is a genuine, current wall-clock timestamp --
        // NOT the period counter reinterpreted as epoch seconds (which
        // would land somewhere in 1970/1971, not "now").
        $this->assertNotNull($factor->last_used_at);
        $this->assertTrue($factor->last_used_at->greaterThanOrEqualTo($beforeVerification->subSecond()));
        $this->assertTrue($factor->last_used_at->lessThanOrEqualTo(now()->addSecond()));
        $this->assertGreaterThan(2000, $factor->last_used_at->year, 'last_used_at must never regress to a Unix-epoch-adjacent year (the historical bug this correction fixes).');

        // Deliberately not asserting any numeric relationship between
        // the two fields -- they are different units entirely, and an
        // accidental equivalence would itself be a red flag, not a
        // reassurance.
    }

    #[Test]
    public function enrollment_confirmation_establishes_a_real_wall_clock_last_used_at_not_an_epoch_adjacent_date(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $code = $this->currentTotpCodeFor($secret);

        app(MfaEnrollmentService::class)->begin($user);
        $pending = $user->mfaFactors()->where('status', 'pending')->first();
        $pending->secret_encrypted = $secret;
        $pending->save();
        app(MfaEnrollmentService::class)->confirm($user, $code);

        $factor = $user->fresh()->mfaFactors()->where('status', 'active')->first();

        $this->assertGreaterThan(2000, $factor->last_used_at->year);
        $this->assertNotNull($factor->last_used_totp_step);
        $this->assertTrue($factor->last_used_at->greaterThan(now()->subMinute()));
    }
}
