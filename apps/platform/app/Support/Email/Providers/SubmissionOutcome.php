<?php

namespace App\Support\Email\Providers;

/**
 * ADR 0055 section 10: an adapter classifies EVERY provider failure into
 * one of these at its boundary -- the core never inspects vendor codes.
 */
enum SubmissionOutcome: string
{
    case Accepted = 'accepted';
    /** Worth a bounded retry: timeouts, network/TLS errors, 408/429/5xx, SMTP 4xx, throttling. */
    case TransientFailure = 'transient_failure';
    /** Never retried: the recipient, sender or content was refused. */
    case PermanentFailure = 'permanent_failure';
    /** Credentials or TLS configuration refused: an operator problem, not the message's. */
    case AuthFailure = 'auth_failure';
}
