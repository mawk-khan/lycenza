<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\Exceptions\MfaAlreadyEnrolledException;
use App\Support\Auth\Mfa\Exceptions\MfaEnrollmentNotPendingException;
use App\Support\Auth\Mfa\Exceptions\MfaInvalidCodeException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Phase 0H.4D-P1 section 8: not configured -> pending -> active.
 * Confirmation failure never activates the pending factor (it simply
 * stays pending -- the User may retry or start over). Fresh-password
 * confirmation for `begin()` is enforced by the CALLER
 * (AccountSecurityController, via PasswordConfirmationService) --
 * this service is HTTP-request-agnostic like every other Application-
 * layer service in this codebase.
 */
class MfaEnrollmentService
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly MfaRecoveryCodeService $recoveryCodes,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{factor: UserMfaFactor, secret: string, otpAuthUri: string}
     *
     * @throws MfaAlreadyEnrolledException
     */
    public function begin(User $user): array
    {
        if ($user->mfaFactors()->where('status', 'active')->exists()) {
            throw new MfaAlreadyEnrolledException;
        }

        // v1 one-active-factor policy (section 9): a stale/abandoned
        // `pending` factor from a previous incomplete attempt is
        // superseded, never accumulated -- an incomplete enrollment
        // must not block a fresh attempt.
        $user->mfaFactors()->where('status', 'pending')->delete();

        $secret = $this->google2fa->generateSecretKey();

        $factor = UserMfaFactor::create([
            'user_id' => $user->id,
            'type' => 'totp',
            'secret_encrypted' => $secret,
            'status' => 'pending',
        ]);

        $this->audit->platform(MfaAuditActions::ENROLLMENT_STARTED, actor: $user, subject: $factor);

        return [
            'factor' => $factor,
            'secret' => $secret,
            'otpAuthUri' => $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret),
        ];
    }

    /**
     * @return list<string> plaintext recovery codes, returned exactly once
     *
     * @throws MfaEnrollmentNotPendingException
     * @throws MfaInvalidCodeException
     */
    public function confirm(User $user, string $code): array
    {
        $factor = $user->mfaFactors()->where('status', 'pending')->latest('created_at')->first();

        if ($factor === null) {
            throw new MfaEnrollmentNotPendingException;
        }

        $window = (int) config('mfa.totp_window');

        // Replay-security correction: always pass an explicit floor
        // (0 -- this factor has never verified successfully) so a
        // match returns the real accepted TOTP step as an integer.
        // Plain verifyKey() with no old-step argument returns the
        // boolean `true` instead of the matched step on success --
        // that boolean, cast to (int), previously primed the replay
        // floor to a meaningless `1` and left the enrollment code
        // itself replayable at the very next login. See
        // MfaChallengeService::verifyTotp() for the identical
        // convention used on every subsequent verification.
        $acceptedStep = $this->google2fa->verifyKeyNewer($factor->secret_encrypted, $code, 0, $window);

        if ($acceptedStep === false) {
            throw new MfaInvalidCodeException;
        }

        try {
            return DB::transaction(function () use ($user, $factor, $acceptedStep) {
                $factor->forceFill([
                    'status' => 'active',
                    'confirmed_at' => now(),
                    // Genuine wall-clock time of first successful use --
                    // never a TOTP counter (see UserMfaFactor's docblock).
                    'last_used_at' => now(),
                    // The replay floor: the ACTUAL accepted step, so the
                    // enrollment code cannot be replayed at the next
                    // login attempt.
                    'last_used_totp_step' => $acceptedStep,
                ])->save();

                $codes = $this->recoveryCodes->issue($user);

                $this->audit->platform(MfaAuditActions::ENROLLED, actor: $user, subject: $factor, metadata: [
                    'factorType' => $factor->type,
                ]);

                return $codes;
            });
        } catch (UniqueConstraintViolationException) {
            // section 9: the DB-level partial unique index
            // (user_mfa_factors_one_active_per_user) is the real
            // guarantee -- this catch only handles the theoretical
            // race of two concurrent confirm() calls for the same
            // User (e.g. two browser tabs), which the earlier
            // begin()-time check cannot see.
            throw new MfaAlreadyEnrolledException;
        }
    }
}
