<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\Exceptions\MfaInvalidCodeException;
use App\Support\Auth\Mfa\Exceptions\MfaNotEnrolledException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.4D-P1 sections 13/20: self-service disable requires BOTH a
 * fresh password confirmation (caller's responsibility, via
 * PasswordConfirmationService -- a stolen session/password alone must
 * never be enough) AND a currently-valid TOTP or recovery code proving
 * continued factor possession, checked here.
 */
class MfaFactorService
{
    public function __construct(
        private readonly MfaChallengeService $challenge,
        private readonly MfaRecoveryCodeService $recoveryCodes,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws MfaNotEnrolledException
     * @throws MfaInvalidCodeException
     */
    public function disable(User $user, string $code): void
    {
        $factor = $this->challenge->activeFactorFor($user);

        if ($factor === null) {
            throw new MfaNotEnrolledException;
        }

        if (! $this->challenge->verifyTotp($factor, $code) && ! $this->recoveryCodes->consume($user, $code)) {
            throw new MfaInvalidCodeException;
        }

        DB::transaction(function () use ($user, $factor) {
            $factor->forceFill(['status' => 'revoked'])->save();

            UserMfaRecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
        });

        $this->audit->platform(MfaAuditActions::DISABLED, actor: $user, subject: $factor);
    }
}
