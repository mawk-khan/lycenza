{{-- Phase 0O.10A (ADR 0056 section 11.4): HTML alternative; escaped, no images. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Your Lycenza password was changed</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.5;">
<p>Your Lycenza password was changed on {{ $changedAt->toDayDateTimeString() }} (UTC).</p>
<p>Every device that was signed in has been signed out. If you made this change, there is nothing else to do.</p>
<p>If you did not change your password, contact your School or Lycenza support immediately.</p>
</body>
</html>
