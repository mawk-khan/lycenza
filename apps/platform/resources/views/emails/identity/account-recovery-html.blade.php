{{-- Phase 0O.10A (ADR 0056 section 9.3): HTML alternative. Repository-owned,
     every value escaped, no remote image, no tracking. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>Reset your Lycenza password</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.5;">
<p>A password reset was requested for your Lycenza account.</p>
<p><a href="{{ $link }}" rel="noreferrer">Choose a new password</a></p>
<p style="font-size: 13px; color: #475569;">The link expires in {{ $minutes }} minutes ({{ $expiresAt->toDayDateTimeString() }} UTC) and can be used once.</p>
<p style="font-size: 13px; color: #475569;">If you did not request this, ignore this email &mdash; your password has not changed.</p>
</body>
</html>
