{{-- Phase 0O.10A (ADR 0056 section 9.3): the account-recovery email, text
     part (authoritative). Identity-level: no School, membership, role or MFA
     detail. The link's secret is in its #fragment. Raw output is correct
     for text/plain (no HTML escaping into a plain-text body). --}}
A password reset was requested for your Lycenza account.

To choose a new password, open this link within {{ $minutes }} minutes:
{!! $link !!}

The link expires at {{ $expiresAt->toDayDateTimeString() }} (UTC) and can be used once.

If you did not request this, ignore this email -- your password has not changed.
