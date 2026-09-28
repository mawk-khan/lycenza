<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Models\User;
use App\Support\Email\EmailPurpose;
use App\Support\Email\OutboundEmailGateway;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * Phase 0O.10A (ADR 0056 section 11.4): after a password change commits, a
 * critical, identity-level `security_notice` to the User's authoritative
 * email -- when, and what to do if it was not them. No link, token, School,
 * role or MFA detail; it respects suppression like all critical mail.
 * Failing to queue it never undoes the password change (it runs after
 * commit and is reported, not thrown).
 */
final class SecurityNoticeService
{
    public const NOTICE_TTL_HOURS = 24;

    public function __construct(private readonly OutboundEmailGateway $email) {}

    public function passwordChanged(User $user): void
    {
        try {
            $when = now();
            $view = ['changedAt' => $when];

            $this->email->queueForIdentity(
                purpose: EmailPurpose::SecurityNotice,
                sourceId: (string) new UuidV7,
                recipient: $user->email,
                subject: 'Your Lycenza password was changed',
                text: view('emails.identity.password-changed', $view)->render(),
                html: view('emails.identity.password-changed-html', $view)->render(),
                expiresAt: $when->copy()->addHours(self::NOTICE_TTL_HOURS),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
