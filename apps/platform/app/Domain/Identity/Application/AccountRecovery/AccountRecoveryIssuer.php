<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Models\User;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Email\EmailPurpose;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Privacy\EmailNormalizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.10A (ADR 0056 sections 5.2, 6, 7, 9): decides and issues, OFF the
 * request path (IssueAccountRecoveryJob). Whatever happens here, the
 * browser already has the generic response.
 *
 * Nothing is issued while recovery is disabled or critical email is not
 * available (MAIL_PROVIDER=none, no/unverified sending domain): a credential
 * nobody can receive is never created. Otherwise, in ONE transaction with
 * the User row locked (so the 3-active cap holds under concurrency): the
 * eligibility rule, the cap, a fresh 256-bit credential (only its SHA-256
 * stored) and the durable `account_recovery` email, expiring together.
 * The provider is contacted only after commit (ADR 0055). A new request
 * NEVER invalidates an older one.
 */
final class AccountRecoveryIssuer
{
    public function __construct(
        private readonly Repository $config,
        private readonly AccountRecoveryEligibility $eligibility,
        private readonly EmailProviderResolver $providers,
        private readonly OutboundEmailGateway $email,
        private readonly CanonicalOrigin $origins,
        private readonly AccountRecoveryTelemetry $telemetry,
    ) {}

    public function issue(string $email): void
    {
        if (! (bool) $this->config->get('account_recovery.enabled')) {
            $this->telemetry->issuance('disabled');

            return;
        }

        if (! $this->providers->criticalEmailAvailable()) {
            $this->telemetry->issuance('email_unavailable');

            return;
        }

        $outcome = DB::transaction(function () use ($email): string {
            $user = User::query()->where('email', EmailNormalizer::canonical($email))->lockForUpdate()->first();

            if ($user === null) {
                return 'unknown';
            }

            if (! $this->eligibility->isEligible($user)) {
                return 'ineligible';
            }

            $open = AccountRecoveryRequest::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')->whereNull('invalidated_at')
                ->where('expires_at', '>', now())
                ->count();

            if ($open >= (int) $this->config->get('account_recovery.max_active_per_user')) {
                return 'active_limit';
            }

            $credential = RecoveryCredential::generate();
            $now = now();
            $expiresAt = $now->copy()->addMinutes((int) $this->config->get('account_recovery.lifetime_minutes'));

            $request = AccountRecoveryRequest::query()->create([
                'selector' => $credential->selector,
                'user_id' => $user->id,
                'secret_hash' => RecoveryCredential::hash($credential->secret),
                'credential_version' => $user->credential_version,
                'email_hash' => hash('sha256', $user->email),
                'created_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            // ADR 0056 section 8.1: the secret travels only in the fragment.
            $link = $this->origins->platformUrl("account-recovery/{$credential->selector}").'#'.$credential->secret;
            $view = ['link' => $link, 'expiresAt' => $expiresAt, 'minutes' => (int) $this->config->get('account_recovery.lifetime_minutes')];

            $message = $this->email->queueForIdentity(
                purpose: EmailPurpose::AccountRecovery,
                sourceId: $request->id,
                recipient: $user->email,
                subject: 'Reset your Lycenza password',
                text: view('emails.identity.account-recovery', $view)->render(),
                html: view('emails.identity.account-recovery-html', $view)->render(),
                expiresAt: $expiresAt,
            );

            $request->forceFill(['email_message_id' => $message->id])->save();

            return 'issued';
        });

        $this->telemetry->issuance($outcome);
    }
}
