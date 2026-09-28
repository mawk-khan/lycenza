{{-- Phase 0O.12B (ADR 0059 section 9.2): the HTML alternative of the staff
     account invitation. Repository-owned, every value escaped with {{ }}, no
     remote image, no tracking, no script, no style import. The text part
     (staff-account-invitation.blade.php) is authoritative. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $schoolName }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.5;">
<p>You have been invited to a staff account at {{ $schoolName }} on {{ $platformName }}.</p>
<p><a href="{{ $acceptanceUrl }}">Accept the invitation</a></p>
<p style="font-size: 13px; color: #475569;">Or copy this link into your browser:<br>{{ $acceptanceUrl }}</p>
<p style="font-size: 13px; color: #475569;">The link expires on {{ $expiresAt->toDayDateTimeString() }} (UTC) and can be used once. If you already have a {{ $platformName }} account under this address, sign in first, then open the link again. If you did not expect this invitation, ignore this email.</p>
</body>
</html>
