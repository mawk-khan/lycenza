Hello {{ $guardianFirstName }},

You've been invited to create a School OS account for {{ $schoolName }}.

Use the link below to set up your account:
{{ $acceptanceUrl }}

This link expires on {{ $expiresAt->toFormattedDateString() }} and can only be used once. If you didn't expect this invitation, you can safely ignore this email.
