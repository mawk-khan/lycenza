{{-- Phase 5A.3: minimal plain-text rendering only (brief §32 -- no
     template administration, no HTML layout, no merge tags). Raw
     output ({!! !!}) is correct here, not a mistake: this is the
     text/plain MIME part of the email, read literally by mail
     clients, not HTML -- Blade's default {{ }} escaping would
     incorrectly turn characters like "<" into HTML entities in what
     must remain plain text. --}}
{!! $bodyText !!}
