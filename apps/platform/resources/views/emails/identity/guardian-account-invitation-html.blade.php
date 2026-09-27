{{-- Phase 0O.9A (ADR 0055 section 13): the HTML alternative of the
     invitation. Repository-owned, every value escaped with {{ }}, no
     remote image, no tracking, no script, no style import. The text part
     (guardian-account-invitation.blade.php) is authoritative. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $schoolName }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.5;">
<p>Hello {{ $guardianFirstName }},</p>
<p>You've been invited to create a School OS account for {{ $schoolName }}.</p>
<p><a href="{{ $acceptanceUrl }}">Set up your account</a></p>
<p style="font-size: 13px; color: #475569;">Or copy this link into your browser:<br>{{ $acceptanceUrl }}</p>
<p style="font-size: 13px; color: #475569;">This link expires on {{ $expiresAt->toFormattedDateString() }} and can only be used once. If you didn't expect this invitation, you can safely ignore this email.</p>
</body>
</html>
