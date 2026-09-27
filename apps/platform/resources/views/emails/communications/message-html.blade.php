{{-- Phase 0O.9A (ADR 0055 section 13): the HTML alternative of a
     Communication Hub email. The body is School-authored PLAIN TEXT
     (Phase 5A.6: there is no rich text and no HTML sanitizer), so it is
     escaped with {{ }} and only its line breaks are kept -- School content
     can never become markup. No remote image, no tracking. The text part
     (message.blade.php) is authoritative. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $subject }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.5;">
<div style="white-space: pre-wrap;">{{ $bodyText }}</div>
</body>
</html>
