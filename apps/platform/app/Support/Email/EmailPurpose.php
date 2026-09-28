<?php

namespace App\Support\Email;

/**
 * ADR 0055 section 3: the CLOSED message-purpose catalog. The purpose is
 * set by the producer in code, never by a School or a request. Since Phase
 * 0O.10A (ADR 0056) all four are implemented; `account_recovery` and
 * `security_notice` are identity-level (no School).
 *
 * Marketing and commercial bulk mail are out of scope for v1.
 */
enum EmailPurpose: string
{
    case AccountInvitation = 'account_invitation';
    case SchoolCommunication = 'school_communication';
    /** ADR 0056 (Phase 0O.10A): self-service password recovery. Identity-level. */
    case AccountRecovery = 'account_recovery';
    /** ADR 0056 (Phase 0O.10A): the post-reset "your password was changed" notice. Identity-level. */
    case SecurityNotice = 'security_notice';

    public function kind(): EmailKind
    {
        return $this === self::SchoolCommunication ? EmailKind::Standard : EmailKind::Critical;
    }

    public function isImplemented(): bool
    {
        return in_array($this, self::implemented(), true);
    }

    /** The one source type each implemented purpose is produced from. */
    public function sourceType(): string
    {
        return match ($this) {
            self::AccountInvitation => 'guardian_account_invitation',
            self::SchoolCommunication => 'communication_delivery',
            self::AccountRecovery => 'account_recovery_request',
            self::SecurityNotice => 'user_security_notice',
        };
    }

    /**
     * ADR 0056 section 9.3: belongs to a human identity, never a School --
     * `school_id` is NULL (database-enforced) and the row lives in the
     * platform email scope.
     */
    public function isIdentityLevel(): bool
    {
        return $this === self::AccountRecovery || $this === self::SecurityNotice;
    }

    /** @return list<self> */
    public static function implemented(): array
    {
        return self::cases();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
