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

    'batch_size' => (int) env('RETENTION_PRUNE_BATCH_SIZE', 500),

];
