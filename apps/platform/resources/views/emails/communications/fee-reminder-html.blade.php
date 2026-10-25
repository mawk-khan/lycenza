{{-- ORG.9D-2 (ADR 0076 §74, PV1-11): the HTML alternative of a fee-due
     reminder. Every School-, Student- and staff-authored value is escaped
     with {{ }} -- nothing here can become markup. No remote image, no
     link, no tracking. The text part (fee-reminder.blade.php) is
     authoritative. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $subject }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.5;">
<p>Dear parent or guardian,</p>
<p>{{ $schoolName }} is reminding you that school fees are outstanding for:</p>
<ul>
@foreach ($lines as $line)
<li>{{ $line['studentName'] }}: {{ $line['currency'] }} {{ $line['amount'] }}</li>
@endforeach
</ul>
<p>Amounts outstanding as of {{ $asOf }}.</p>
<div style="white-space: pre-wrap;">{{ $note }}</div>
<p>If you have already paid, please disregard this reminder. For any question about these fees, please contact the school.</p>
</body>
</html>
