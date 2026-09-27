<?php

namespace App\Support\Email;

use App\Models\EmailMessage;

/**
 * ADR 0055 section 9.1: the product record an email belongs to (a Guardian
 * account invitation, a Communication delivery). The email layer never
 * reads another module's tables: the owning module registers one of these
 * (EmailServiceProvider) and the email layer calls it, inside the School's
 * TenantContext.
 */
interface EmailSource
{
    /** The closed `email_messages.source_type` this source owns. */
    public function sourceType(): string;

    /**
     * Re-checked when a message is claimed for submission (brief step 83):
     * a revoked invitation, or a cancelled delivery, must never be sent.
     * Never consulted once the provider has accepted the message.
     */
    public function isStillWanted(EmailMessage $message): bool;

    /** Mirror the message's (new) transport state onto the product record. */
    public function project(EmailMessage $message): void;
}
