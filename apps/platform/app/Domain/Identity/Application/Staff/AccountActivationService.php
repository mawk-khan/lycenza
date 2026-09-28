<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Application\Credentials\CredentialChangeService;
use App\Domain\Identity\Infrastructure\AccountActivationCredential;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 0O.12B (ADR 0059 sections 5, 10): consumes a bootstrap account's
 * one-time activation credential and sets its FIRST password.
 *
 * Lock order (every credential writer): the USER row first, then the
 * credential row. In one transaction: verify the credential (constant-time
 * hash, open, unexpired, same credential version as at issuance), the User
 * (enabled, STILL credential-less -- activation is never a reset), apply
 * `Password::defaults()` + confirmation, consume the credential, then
 * CredentialChangeService::establishInitialPassword() and the platform audit
 * `auth.account_activated`. No auto-login; MFA is not touched or embedded.
 * Two submissions of one link, or activation racing a re-issue: the User
 * lock serializes them; the loser sees the credential ended (or the version
 * moved) and gets the generic invalid outcome.
 */
final class AccountActivationService
{
    public const ACTIVATED = 'auth.account_activated';

    public function __construct(
        private readonly CredentialChangeService $credentials,
        private readonly AuditRecorder $audit,
    ) {}

    public function activate(string $selector, string $secret, string $password, string $passwordConfirmation): CredentialOutcome
    {
        $outcome = $this->attempt($selector, $secret, $password, $passwordConfirmation);
        Log::info('auth.account_activation', ['outcome' => $outcome->outcome]);

        return $outcome;
    }

    private function attempt(string $selector, string $secret, string $password, string $passwordConfirmation): CredentialOutcome
    {
        if (! OneTimeCredential::wellFormed($selector, $secret)) {
            return CredentialOutcome::of(CredentialOutcome::INVALID);
        }

        $userId = AccountActivationCredential::query()->where('selector', $selector)->value('user_id');

        if ($userId === null) {
            return CredentialOutcome::of(CredentialOutcome::INVALID);
        }

        return DB::transaction(function () use ($userId, $selector, $secret, $password, $passwordConfirmation): CredentialOutcome {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            $credential = AccountActivationCredential::query()->where('selector', $selector)->lockForUpdate()->first();

            if ($user === null || $credential === null
                || ! $credential->isOpen()
                || ! OneTimeCredential::matches($secret, $credential->secret_hash)
                || $credential->credential_version !== $user->credential_version
                || $user->isDisabled()
                || $user->hasLocalCredential()) {
                return CredentialOutcome::of(CredentialOutcome::INVALID);
            }

            $validator = Validator::make(
                ['password' => $password, 'password_confirmation' => $passwordConfirmation],
                ['password' => ['required', 'confirmed', Password::defaults()]],
            );

            if ($validator->fails()) {
                return CredentialOutcome::policyRejected($validator->errors());
            }

            $credential->forceFill(['consumed_at' => now()])->save();
            $this->credentials->establishInitialPassword($user, $password);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            $this->audit->platform(self::ACTIVATED, actor: $user, subject: $user, metadata: [
                'user_id' => $user->id,
                'activation_credential_id' => $credential->id,
                'method' => 'platform_activation',
            ]);

            return CredentialOutcome::of(CredentialOutcome::ACTIVATED);
        });
    }
}
