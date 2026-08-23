<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Channel Enablement (Phase 5A.3 brief §4)
    |--------------------------------------------------------------------------
    |
    | A configured Laravel `MAIL_MAILER` does NOT by itself mean
    | Communication Hub email should leave the system -- this gate is
    | Communication Hub's OWN explicit switch, independent of whether
    | mail is configured for some other purpose (password resets, a
    | future notification, ...). Defaults to false: deploying this
    | checkpoint activates nothing until a human explicitly flips it.
    |
    | `mailer` optionally names a specific config/mail.php mailer to use
    | for Communication Hub email; null means "use the application's
    | default mailer" (config('mail.default')).
    |
    */

    'channels' => [
        'email' => [
            'enabled' => (bool) env('COMMUNICATION_EMAIL_ENABLED', false),
            'mailer' => env('COMMUNICATION_EMAIL_MAILER'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Retry Policy (brief §21)
    |--------------------------------------------------------------------------
    |
    | Channel-agnostic (not "email.max_attempts") so a future real SMS/
    | WhatsApp/Push driver reuses the exact same
    | App\Jobs\ProcessCommunicationDeliveryJob retry state machine
    | without a second config block. Mirrors config/webhooks.php's
    | retry_backoff_seconds shape exactly: indexed by
    | (attempt_number - 1), the last entry repeats for any attempt
    | beyond the array's length. Deliberately much shorter than
    | webhooks' schedule (max 3 attempts, minutes not hours) --
    | Communication Hub delivery is user-facing and time-sensitive
    | (someone is waiting to see whether an announcement went out),
    | unlike a webhook integration that can reasonably retry for a day.
    |
    */

    'delivery' => [
        'max_attempts' => (int) env('COMMUNICATION_DELIVERY_MAX_ATTEMPTS', 3),

        'retry_backoff_seconds' => [30, 120, 300],

        'processing_lease_seconds' => (int) env('COMMUNICATION_DELIVERY_PROCESSING_LEASE_SECONDS', 30),
    ],

];
