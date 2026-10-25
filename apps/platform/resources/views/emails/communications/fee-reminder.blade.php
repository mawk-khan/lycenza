{{-- ORG.9D-2 (ADR 0076 §74, PV1-11): the text/plain part of a fee-due
     reminder. Rendered ONLY from this recipient's immutable
     communication_fee_reminder_lines (written at publish) and the
     School-authored note -- never a Charge, payment or concession, and
     never a live re-read. Raw output is correct here: this is the
     text/plain MIME part (see message.blade.php). The HTML part escapes. --}}
Dear parent or guardian,

{!! $schoolName !!} is reminding you that school fees are outstanding for:

@foreach ($lines as $line)
- {!! $line['studentName'] !!}: {{ $line['currency'] }} {{ $line['amount'] }}
@endforeach

Amounts outstanding as of {{ $asOf }}.

{!! $note !!}

If you have already paid, please disregard this reminder. For any question about these fees, please contact the school.
