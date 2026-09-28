<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Domain\Identity\Application\Credentials\CredentialChangeService;
use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 0O.10A (ADR 0056 section 10): the single-use, atomic reset.
 *
 * Lock order (every credential writer uses it): the USER row first, then
 * the request row. In one transaction: verify the credential (hash in
 * constant time, open, same credential version and email as at issuance,
 * User still eligible), apply the ONE password policy
 * (`Password::defaults()` + confirmation), supersede the User's other open
 * requests, consume this one, then CredentialChangeService (password,
 * credential_version, remember token, human personal access tokens,
 * elevation) and the platform audit `auth.password_recovered`. The
 * `security_notice` email is queued only after commit. No provider call,
 * no password or secret in any job, log or audit.
 *
 * Two different valid credentials of one User, or the same one twice: the
 * User lock serializes them; the loser sees the version (or consumption)
 * changed and gets the generic invalid outcome. Exactly one password
 * change.
 */
final class AccountRecoveryResetService
{
    public function __construct(
        private readonly AccountRecoveryEligibility $eligibility,
        private readonly CredentialChangeService $credentials,
        private readonly SecurityNoticeService $notices,
        private readonly AuditRecorder $audit,
        private readonly AccountRecoveryTelemetry $telemetry,
    ) {}

    public function reset(string $selector, string $secret, string $password, string $passwordConfirmation): ResetOutcome
    {
        $outcome = $this->attempt($selector, $secret, $password, $passwordConfirmation);
        $this->telemetry->reset($outcome->outcome);
        Log::info('auth.account_recovery.reset', ['outcome' => $outcome->outcome]);

        return $outcome;
    }

    private function attempt(string $selector, string $secret, string $password, string $passwordConfirmation): ResetOutcome
    {
        if (preg_match(RecoveryCredential::SELECTOR_PATTERN, $selector) !== 1 || preg_match(RecoveryCredential::SECRET_PATTERN, $secret) !== 1) {
            return ResetOutcome::invalid();
        }

        $userId = AccountRecoveryRequest::query()->where('selector', $selector)->value('user_id');

        if ($userId === null) {
            return ResetOutcome::invalid();
        }

        return DB::transaction(function () use ($userId, $selector, $secret, $password, $passwordConfirmation): ResetOutcome {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            $request = AccountRecoveryRequest::query()->where('selector', $selector)->lockForUpdate()->first();

            if ($user === null || $request === null
                || ! $request->isOpen()
                || ! RecoveryCredential::matches($secret, $request->secret_hash)
                || $request->credential_version !== $user->credential_version
                || ! hash_equals($request->email_hash, hash('sha256', $user->email))
                || ! $this->eligibility->isEligible($user)) {
                return ResetOutcome::invalid();
            }

            $validator = Validator::make(
                ['password' => $password, 'password_confirmation' => $passwordConfirmation],
                ['password' => ['required', 'confirmed', Password::defaults()]],
            );

            if ($validator->fails()) {
                return ResetOutcome::policyRejected($validator->errors());
            }

            // Others first, then this one -- both before the credential
            // version changes (the database invalidates any still open).
            AccountRecoveryRequest::query()
                ->where('user_id', $user->id)
                ->whereKeyNot($request->id)
                ->whereNull('consumed_at')->whereNull('invalidated_at')
                ->update(['invalidated_at' => now(), 'invalidation_reason' => 'superseded_by_reset']);

            $request->forceFill(['consumed_at' => now()])->save();

            $result = $this->credentials->setPassword($user, $password);

            $this->audit->platform('auth.password_recovered', actor: $user, subject: $user, metadata: [
                'account_recovery_request_id' => $request->id,
                'revoked_personal_access_tokens' => $result->revokedPersonalAccessTokens,
                'ended_elevation' => $result->endedElevation,
            ]);

            DB::afterCommit(fn () => $this->notices->passwordChanged($user));

            return ResetOutcome::succeeded();
        });
    }
}
