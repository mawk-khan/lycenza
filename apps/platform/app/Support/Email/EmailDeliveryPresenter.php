<?php

namespace App\Support\Email;

use App\Models\EmailMessage;

/**
 * ADR 0055 (Phase 0O.9A): what a School user may see about an email --
 * the closed state and, while it waits, a closed reason. Never the
 * address, the provider, a provider message or error text. "Queued" or
 * "sent to the provider" is never presented as delivered.
 */
final class EmailDeliveryPresenter
{
    /** Waiting reasons a School user is told about; anything else is plain "queued". */
    public const VISIBLE_WAITING_REASONS = ['email_disabled', 'sending_not_verified', 'sending_domain_missing', 'provider_auth_failure', 'provider_auth_paused', 'school_not_operational'];

    /** @return array{state: string, waitingReason: string|null}|null */
    public static function present(?EmailMessage $message): ?array
    {
        if ($message === null) {
            return null;
        }

        $waiting = $message->status === EmailState::Pending && in_array($message->status_code, self::VISIBLE_WAITING_REASONS, true)
            ? $message->status_code
            : null;

        return ['state' => $message->status->value, 'waitingReason' => $waiting];
    }
}
