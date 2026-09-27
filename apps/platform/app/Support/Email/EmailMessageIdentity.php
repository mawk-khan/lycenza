<?php

namespace App\Support\Email;

/**
 * ADR 0055 section 9.3: three identifiers, kept apart.
 *
 * - The INTERNAL message id: the `email_messages.id` UUIDv7.
 * - The RFC 5322 Message-ID header: `<{id}@{sending domain}>`, fixed at
 *   creation (stored as `rfc_message_id`) and reused on every attempt.
 * - The provider IDEMPOTENCY KEY: `lycenza-email-{id}`, the same for every
 *   attempt of one logical email; an adapter uses it only where its
 *   provider supports one (never assumed to share the Message-ID syntax).
 *
 * The PROVIDER message id is whatever the provider returns on acceptance
 * (`provider_message_id`); it is never derived here.
 */
final class EmailMessageIdentity
{
    public static function rfcMessageId(string $messageId, string $sendingDomain): string
    {
        return '<'.strtolower($messageId).'@'.strtolower($sendingDomain).'>';
    }

    public static function idempotencyKey(string $messageId): string
    {
        return 'lycenza-email-'.strtolower($messageId);
    }
}
