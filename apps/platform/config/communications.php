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
    | Phase 0O.9A (ADR 0055): Communication Hub email is handed to the
    | platform email layer (config/email.php, MAIL_PROVIDER); the former
    | `mailer` setting (COMMUNICATION_EMAIL_MAILER) was removed with the
    | direct Laravel Mail call. MAIL_PROVIDER=none also refuses the channel.
    |
    */

    'channels' => [
        'email' => [
            'enabled' => (bool) env('COMMUNICATION_EMAIL_ENABLED', false),
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

    /*
    |--------------------------------------------------------------------------
    | Scheduled Announcement Publication (Phase 5A.4 §19/§23/§39)
    |--------------------------------------------------------------------------
    |
    | `App\Console\Commands\PublishScheduledAnnouncements` never claims a
    | second time via its own lease -- App\Domain\Communications\Application\
    | AnnouncementService::publish() already claims atomically in ONE
    | transaction (draft/due-scheduled -> published), so a crash between
    | claim and commit is impossible (nothing partially commits). The
    | one genuine failure-recovery need is a due announcement whose
    | publish() attempt THROWS (e.g. EmptyAudienceException) and rolls
    | back to `scheduled` -- `failure_backoff_seconds` is how far the
    | command pushes `scheduled_at` forward before it will be retried
    | again, so a permanently-empty-audience Announcement retries every
    | 15 minutes forever (a genuinely transient condition -- membership
    | can change) rather than every minute (brief §23: "do not create an
    | uncontrolled infinite retry loop").
    |
    */

    'scheduling' => [
        'batch_size' => (int) env('COMMUNICATION_SCHEDULING_BATCH_SIZE', 100),

        'failure_backoff_seconds' => (int) env('COMMUNICATION_SCHEDULING_FAILURE_BACKOFF_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments (Phase 5A.6 §9/§14/§16/§17/§29)
    |--------------------------------------------------------------------------
    |
    | `disk` is Communication Hub's OWN explicit storage choice --
    | independent of `config('filesystems.default')` -- so a future unrelated change to
    | the application's default disk never silently relocates
    | attachment storage. Defaults to `local` (storage_path('app/private'),
    | already private -- see config/filesystems.php), never `public`.
    |
    | `allowed_mime_types` maps a real, sniffed MIME type (never the
    | client-supplied one -- brief §15) to the file extension(s) it may
    | be declared with; a mismatch between the sniffed type and the
    | uploaded filename's extension is rejected exactly like a
    | disallowed type is. Deliberately excludes SVG (brief §14: "avoid
    | casual SVG support" -- SVG can embed script) and every macro-
    | enabled Office format (.docm/.xlsm/...).
    |
    | `email_max_total_size_mb` (brief §29) is a SEPARATE, smaller
    | threshold from `max_total_size_mb` -- the canonical storage limit
    | for what a message may hold at all vs. what EmailChannelDriver is
    | willing to actually transmit as a MIME attachment. Exceeding it
    | does not touch the canonical attachment or the IN_APP channel --
    | see EmailChannelDriver's `attachment_email_size_exceeded` handling.
    |
    */

    'attachments' => [
        'disk' => env('COMMUNICATION_ATTACHMENTS_DISK', 'local'),

        'max_file_size_mb' => (int) env('COMMUNICATION_ATTACHMENTS_MAX_FILE_SIZE_MB', 10),
        'max_per_message' => (int) env('COMMUNICATION_ATTACHMENTS_MAX_PER_MESSAGE', 5),
        'max_total_size_mb' => (int) env('COMMUNICATION_ATTACHMENTS_MAX_TOTAL_SIZE_MB', 25),

        'email_max_total_size_mb' => (int) env('COMMUNICATION_ATTACHMENTS_EMAIL_MAX_TOTAL_SIZE_MB', 8),

        'allowed_mime_types' => [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        ],
    ],

];
