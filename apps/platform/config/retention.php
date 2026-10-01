<?php

/*
 * E21 retention (docs/security/E21-RETENTION-DETERMINATION.md):
 * project-adopted periods, pending final legal/compliance ratification.
 * Every period is a raw value with NO default: a prune deletes nothing
 * while its period is unset (the MAIL_RETENTION_DAYS precedent).
 * Production sets the adopted values listed in the determination (§6).
 */
return [

    /*
     * E21 legal-hold seam (determination §2): Schools whose data no
     * retention command may delete. Comma-separated School ids. A predicate,
     * not case management.
     */
    'hold_school_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('RETENTION_HOLD_SCHOOL_IDS', ''))))),

    // E21-D4: processed domain-event outbox rows (and their consumer receipts).
    'outbox_days' => env('OUTBOX_RETENTION_DAYS'),

    // E21-D13: failed_jobs rows after their terminal failure.
    'failed_jobs_days' => env('FAILED_JOBS_RETENTION_DAYS'),

    // E21.2B: hold every record that belongs to no School (RetentionHolds::platformHeld()).
    'hold_platform' => filter_var(env('RETENTION_HOLD_PLATFORM', false), FILTER_VALIDATE_BOOLEAN),

    // E21-D1: audit ledgers, calendar years after `occurred_at` (adopted: 7).
    'audit_years' => env('AUDIT_RETENTION_YEARS'),

    // E21-D6: revoked/ended authority, calendar years after the end (adopted: 7).
    'authority_history_years' => env('AUTHORITY_HISTORY_RETENTION_YEARS'),

    // E21-D2: released email suppressions, calendar years after release (adopted: 1).
    'released_suppression_years' => env('MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS'),

    // E21-D3: Communications content, calendar years after the END of the
    // Academic Year it was sent in (adopted: 3); delivery telemetry,
    // calendar years after the terminal delivery time (adopted: 1).
    'communications_content_years' => env('COMMUNICATIONS_CONTENT_RETENTION_YEARS'),
    'communications_delivery_years' => env('COMMUNICATIONS_DELIVERY_RETENTION_YEARS'),

    // E21-D5: proven orphan objects, days after last modification (adopted: 30).
    'orphan_object_days' => env('STORAGE_ORPHAN_RETENTION_DAYS'),
    'orphan_scan_limit' => (int) env('RETENTION_ORPHAN_SCAN_LIMIT', 10000),

    // E21.2D (E21-D7, project-adopted, pending ratification): calendar years
    // after a Student's final exit (StudentRetentionEligibility). Operational
    // history (attendance, rollover items, Guardian relationships), adopted 7;
    // the core academic record (identity, placements, subject enrollments,
    // Student Documents), adopted 25. No default: unset deletes nothing.
    'student_operational_years' => env('STUDENT_OPERATIONAL_RETENTION_YEARS'),
    'student_core_years' => env('STUDENT_CORE_RETENTION_YEARS'),

    'batch_size' => (int) env('RETENTION_PRUNE_BATCH_SIZE', 500),

];
