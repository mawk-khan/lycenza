<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guardian contact exact-match lookup digest (Phase 1A.3)
    |--------------------------------------------------------------------------
    |
    | A dedicated secret for App\Support\Privacy\ContactLookupHasher --
    | deliberately NOT the application APP_KEY (which encrypts
    | guardian_contacts.encrypted_value via Laravel's built-in
    | `encrypted` Eloquent cast) so the two can be rotated
    | independently. See docs/modules/STUDENT-GUARDIAN-IDENTITY.md
    | ("Searchable PII / lookup digest").
    |
    | ContactLookupHasher fails closed (throws
    | ContactLookupKeyNotConfiguredException) if hmac_key is empty --
    | it never falls back to hashing with a missing/predictable key.
    |
    */

    'contact_lookup' => [
        'hmac_key' => env('CONTACT_LOOKUP_HMAC_KEY'),

        // Bumped whenever hmac_key is rotated -- stored per-row
        // (guardian_contacts.lookup_key_version) so a future rotation
        // can identify which rows still need re-hashing under the new
        // key. No rotation workflow (a backfill job re-hashing every
        // row) is implemented in Phase 1A.3 -- this column only makes
        // that future work possible without a schema change.
        'hmac_key_version' => (int) env('CONTACT_LOOKUP_HMAC_KEY_VERSION', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Statutory identifier exact-match lookup digest (Checkpoint 9.6C)
    |--------------------------------------------------------------------------
    |
    | A dedicated secret for
    | App\Support\Privacy\StatutoryIdentifierLookupHasher (PAN/UAN/PF
    | Member ID/ESIC IP Number) -- deliberately its OWN key, separate
    | from both APP_KEY and contact_lookup.hmac_key, so each secret can
    | be rotated independently and a compromise of one never implies
    | the other. See ADR 0036 and ADR 0041's identical precedent.
    |
    */

    'statutory_identifier_lookup' => [
        'hmac_key' => env('STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY'),
        'hmac_key_version' => (int) env('STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY_VERSION', 1),
    ],

];
