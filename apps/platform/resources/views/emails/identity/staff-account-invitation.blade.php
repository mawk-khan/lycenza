{{-- Phase 0O.12B (ADR 0059 section 9.2): the staff account invitation email,
     text part (authoritative). Names only the inviting School -- never
     another School, a role or an account state. The link's secret is in its
     #fragment. Raw output is correct for text/plain. --}}
You have been invited to a staff account at {{ $schoolName }} on {{ $platformName }}.

To accept, open this link before it expires:
{!! $acceptanceUrl !!}

The link expires on {{ $expiresAt->toDayDateTimeString() }} (UTC) and can be used once. If you already have a {{ $platformName }} account under this address, sign in first, then open the link again.

If you did not expect this invitation, ignore this email.
