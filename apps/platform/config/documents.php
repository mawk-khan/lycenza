<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage (Phase 0E.2, ADR 0012)
    |--------------------------------------------------------------------------
    |
    | `disk` is the Documents module's OWN explicit storage choice --
    | independent of `config('filesystems.default')`, the same
    | reasoning `config('communications.attachments.disk')` already
    | established -- so a future unrelated change to the application's
    | default disk never silently relocates Document storage. Defaults
    | to `local` (storage_path('app/private'), already private -- see
    | config/filesystems.php); a real deployment sets DOCUMENTS_DISK=s3
    | against the MinIO/S3-compatible endpoint ADR 0011 fixes as the
    | storage technology.
    |
    */

    'disk' => env('DOCUMENTS_DISK', 'local'),

    'max_file_size_mb' => (int) env('DOCUMENTS_MAX_FILE_SIZE_MB', 10),

    /*
    |--------------------------------------------------------------------------
    | Allowed types
    |--------------------------------------------------------------------------
    |
    | Maps a real, server-sniffed MIME type (never the client-supplied
    | one) to the file extension(s) it may be declared with -- a
    | mismatch between the sniffed type and the uploaded filename's
    | extension is rejected exactly like a disallowed type is. Mirrors
    | `config('communications.attachments.allowed_mime_types')`'s exact
    | shape and its exclusions (no SVG -- can embed script; no
    | macro-enabled Office formats).
    |
    */

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

];
