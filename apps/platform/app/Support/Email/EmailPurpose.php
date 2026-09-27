<?php

namespace App\Support\Email;

/**
 * ADR 0055 section 3: the CLOSED message-purpose catalog. The purpose is
 * set by the producer in code, never by a School or a request. Reserved
 * purposes exist as names only: the database refuses them
 * (`email_messages_purpose_check`) and OutboundEmailGateway refuses them,
 * until their own contract lands (account_recovery is O14).
 *
 * Marketing and commercial bulk mail are out of scope for v1.
 */
enum EmailPurpose: string
{
    case AccountInvitation = 'account_invitation';
    case SchoolCommunication = 'school_communication';
    /** Reserved for O14 -- no route, token, template or producer. */
    case AccountRecovery = 'account_recovery';
    /** Reserved -- no security notice is sent by email today. */
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
    public function sourceType(): ?string
    {
        return match ($this) {
            self::AccountInvitation => 'guardian_account_invitation',
            self::SchoolCommunication => 'communication_delivery',
            default => null,
        };
    }

    /** @return list<self> */
    public static function implemented(): array
    {
        return [self::AccountInvitation, self::SchoolCommunication];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
