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

    // E21.2F (E21-D10): a closed (denied or completed) erasure case, calendar
    // years after it closed (adopted: 7). No default: unset keeps cases.
    'erasure_case_years' => env('ERASURE_CASE_RETENTION_YEARS'),

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
    // history (attendance, rollover items, Guardian relationships; E21.3B:
    // returned Library loans, ended Transport/Hostel assignments), adopted 7;
    // the core academic record (identity, placements, subject enrollments,
    // Student Documents; E21.3B: with processing authorizations, converted
    // admissions, consent events and domain preferences), adopted 25. No
    // default: unset deletes nothing.
    'student_operational_years' => env('STUDENT_OPERATIONAL_RETENTION_YEARS'),
    'student_core_years' => env('STUDENT_CORE_RETENTION_YEARS'),

    // E21.2E (E21-D9, project-adopted, pending ratification): calendar years
    // after an Employee's final separation (EmployeeRetentionEligibility).
    // Ancillary personal sub-records, adopted 2; employment and payroll
    // evidence with the Employee root, adopted 8. No default: unset deletes
    // nothing.
    'employee_ancillary_years' => env('EMPLOYEE_ANCILLARY_RETENTION_YEARS'),
    'employee_evidence_years' => env('EMPLOYEE_EVIDENCE_RETENTION_YEARS'),

    // E21.3A2 (E21-D8, ADR 0064, project-adopted, pending ratification):
    // calendar years after a financial period's CLOSE, adopted 8; less than
    // 8 is refused (the database floor is 8). Deletion additionally needs
    // the explicit switch: off by default, so deploying the code deletes
    // nothing. Enable only after the backfill, ambiguity resolution, the
    // historical closes and a passing platform:finance-balances-verify.
    'finance_years' => env('FINANCE_RETENTION_YEARS'),
    'finance_enabled' => filter_var(env('FINANCE_RETENTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // E21.3B (E21.2G I2, project-adopted, pending ratification): days after an
    // ended portal invitation (identity_account_invitations) ended -- accepted,
    // revoked, or expired unaccepted -- adopted 7. No default: unset deletes
    // nothing. The invitation secret is unusable from the moment it ended;
    // this is only how long its metadata stays.
    'portal_invitation_days' => env('PORTAL_INVITATION_RETENTION_DAYS'),

    // E21.3C (E21.2G AD2/G1, project-adopted, pending ratification): calendar
    // years after a rejected/withdrawn application's canonical `terminal_at`,
    // adopted 1; and after a Guardian last had a Student relationship
    // (`guardians.no_relationship_since`), adopted 1 (the database floor of
    // its consent evidence is 1). No default: unset deletes nothing.
    'admissions_terminal_years' => env('ADMISSIONS_TERMINAL_RETENTION_YEARS'),
    'guardian_years' => env('GUARDIAN_RETENTION_YEARS'),

    // E21.3D (E21.2G A1, project-adopted, pending ratification): calendar
    // years after the end of the authoritative Academic Year of a curriculum
    // delivery, an empty attendance register header, a timetable entry, or an
    // LMS Learning Content/Assignment, adopted 7 (the LMS database floor is 7;
    // teacher-owned LMS also needs authority_history_years). No default:
    // unset deletes nothing.
    'academic_operations_years' => env('ACADEMIC_OPERATIONS_RETENTION_YEARS'),

    // E21.3E (E21.2G C1/C2/O2/O3/O4, project-adopted, pending ratification),
    // calendar years, no default (unset deletes nothing):
    // - never-sent cancelled/rejected announcements and empty threads after
    //   their cancellation/rejection or last activity, adopted 1;
    // - ended driver assignments after `ends_on`, adopted 7;
    // - checked-out visits after `checked_out_at`, adopted 1;
    // - completed automation executions after `completed_at`, adopted 1.
    // Ended API credentials are D6 authority history (authority_history_years).
    'communications_abandoned_years' => env('COMMUNICATIONS_ABANDONED_RETENTION_YEARS'),
    'driver_assignment_years' => env('OPERATIONS_DRIVER_ASSIGNMENT_RETENTION_YEARS'),
    'visit_years' => env('OPERATIONS_VISIT_RETENTION_YEARS'),
    'automation_years' => env('OPERATIONS_AUTOMATION_RETENTION_YEARS'),

    'batch_size' => (int) env('RETENTION_PRUNE_BATCH_SIZE', 500),

];
