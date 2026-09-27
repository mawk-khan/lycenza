<?php

namespace App\Support\Email\Events;

/**
 * ADR 0055 section 11.1: the CLOSED normalized event set. Vendor event
 * names never leave the event adapter; the core never branches on them.
 */
enum EmailEventType: string
{
    case Delivered = 'delivered';
    case Deferred = 'deferred';
    case BounceTransient = 'bounce_transient';
    case BouncePermanent = 'bounce_permanent';
    case Complaint = 'complaint';
    /** The provider refused or dropped an accepted message (provider-side suppression included). */
    case Rejected = 'rejected';
    /** Anything else (opens and clicks -- disabled anyway -- and unknown types). */
    case Ignored = 'ignored';

    /** The closed bounce/reject sub-classes an adapter may report. */
    public const BOUNCE_CLASSES = ['mailbox_unknown', 'mailbox_full', 'domain_invalid', 'policy_rejected', 'content_rejected', 'provider_suppressed', 'other'];
}
