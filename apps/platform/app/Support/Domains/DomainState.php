<?php

namespace App\Support\Domains;

/**
 * ADR 0054 section 4.1: the explicit custom-domain lifecycle. The database
 * (trg_school_domains_guard_update) enforces the same transition table for
 * every role; this enum is the application's vocabulary for it.
 */
enum DomainState: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case TlsPending = 'tls_pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /** Rows that hold their hostname (the partial unique index). */
    public const CLAIMING = ['pending_verification', 'verified', 'tls_pending', 'active', 'suspended'];

    /** Rows the TLS probe endpoint answers for (ADR 0054 section 6.2). */
    public const PROBE_ELIGIBLE = ['tls_pending', 'active', 'suspended'];

    public function isTerminal(): bool
    {
        return $this === self::Revoked || $this === self::Expired;
    }

    /** The School-facing label (ADR 0054 section 10.4). Never a reason. */
    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Waiting for DNS verification',
            self::Verified => 'DNS verified',
            self::TlsPending => 'Preparing secure connection',
            self::Active => 'Active',
            self::Suspended => 'Attention required',
            self::Revoked => 'Removed',
            self::Expired => 'Expired',
        };
    }
}
