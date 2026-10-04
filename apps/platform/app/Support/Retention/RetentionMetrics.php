<?php

namespace App\Support\Retention;

use App\Support\Observability\MetricsRecorder;

/**
 * E21 retention counters: `lycenza_retention_rows_total{operation, outcome}`.
 * Closed values only, counts only, never an identifier.
 *
 * E21.3A2: the `operation` label is a small, stable FAMILY (audit, email,
 * authority, communications, storage, student, employee, erasure,
 * finance; E21.3D: academic; E21.3E: operations), not one value per
 * category: the category list kept growing
 * towards the label ceiling (MetricCatalogGuardTest). Logs keep the exact
 * category; a new category joins an existing family, or a new family is a
 * deliberate catalog change.
 */
final class RetentionMetrics
{
    public const OUTCOMES = ['eligible', 'deleted', 'held', 'skipped', 'unresolved', 'dependency_blocked', 'error'];

    /** E21.2C maintenance categories that are not database retention functions. */
    public const COMMUNICATION_CONTENT = 'communication_content';

    public const COMMUNICATION_DELIVERY = 'communication_delivery';

    public const STORAGE_ORPHAN = 'storage_orphan';

    /** E21.2D (E21-D7) Student categories; their units are Students. */
    public const STUDENT_ATTENDANCE = 'student_attendance';

    public const STUDENT_ROLLOVER_ITEM = 'student_rollover_item';

    public const STUDENT_GUARDIAN_RELATIONSHIP = 'student_guardian_relationship';

    public const STUDENT_CORE = 'student_core';

    /** E21.3B (E21.2G O1) Student operational module categories; their units are Students. */
    public const STUDENT_LIBRARY_LOAN = 'student_library_loan';

    public const STUDENT_TRANSPORT_ASSIGNMENT = 'student_transport_assignment';

    public const STUDENT_HOSTEL_RESIDENCY = 'student_hostel_residency';

    /** E21.3C (E21.2G AD2): non-converted terminal applications; units are applicants. */
    public const ADMISSION_APPLICATION = 'admission_application';

    /** E21.3C (E21.2G G1): Guardian personal data; units are Guardians. */
    public const GUARDIAN_RECORD = 'guardian_record';

    /** E21.3D (E21.2G A1) year-bound academic operations; units are rows (LMS: resources). */
    public const CURRICULUM_DELIVERY = 'curriculum_delivery';

    public const ATTENDANCE_SESSION = 'attendance_session';

    public const TIMETABLE_ENTRY = 'timetable_entry';

    public const LEARNING_CONTENT = 'learning_content';

    public const ASSIGNMENT = 'assignment';

    /** E21.3E (E21.2G C1/C2): never-sent cancelled/rejected announcements and empty threads. */
    public const COMMUNICATION_NEVER_SENT = 'communication_never_sent';

    public const COMMUNICATION_EMPTY_THREAD = 'communication_empty_thread';

    /** E21.3E (E21.2G O2/O3/O4): operational module residuals. */
    public const DRIVER_ASSIGNMENT = 'driver_assignment';

    public const VISITOR_VISIT = 'visitor_visit';

    public const AUTOMATION_EXECUTION = 'automation_execution';

    /** E21.3B (E21.2G I2): ended portal invitations, 7 days after they ended. */
    public const PORTAL_INVITATION = 'portal_invitation';

    /** E21.2E (E21-D9) Employee categories; their units are Employees. */
    public const EMPLOYEE_ANCILLARY = 'employee_ancillary';

    public const PAYROLL_EMPLOYEE_RECORD = 'payroll_employee_record';

    public const EMPLOYEE_EVIDENCE = 'employee_evidence';

    /** E21.3F (E21-D9): posted payroll evidence (units: Employees) and emptied payroll runs (units: regular runs). */
    public const PAYROLL_EVIDENCE = 'payroll_evidence';

    public const PAYROLL_RUN = 'payroll_run';

    /** HRX.6 (E21-D9): one Employee's Leave / Staff Attendance evidence (units: Employees). */
    public const LEAVE_EVIDENCE = 'leave_evidence';

    public const STAFF_ATTENDANCE_EVIDENCE = 'staff_attendance_evidence';

    /** E21.3A2 (E21-D8): settled Finance units of periods closed >= 8 years ago. */
    public const FINANCE_UNIT = 'finance_unit';

    /** category => metric family (the `operation` label value). */
    private const FAMILIES = [
        RetentionExpiry::SCHOOL_AUDIT => 'audit',
        RetentionExpiry::PLATFORM_AUDIT => 'audit',
        RetentionExpiry::RELEASED_SUPPRESSION => 'email',
        RetentionExpiry::SCHOOL_ROLE_GRANT => 'authority',
        RetentionExpiry::TEACHING_ASSIGNMENT => 'authority',
        RetentionExpiry::SCHOOL_ELEVATION => 'authority',
        RetentionExpiry::GROUP_ROLE_GRANT => 'authority',
        RetentionExpiry::PLATFORM_ROLE_GRANT => 'authority',
        RetentionExpiry::COMMUNICATION_POLICY_DECISION => 'communications',
        self::COMMUNICATION_CONTENT => 'communications',
        self::COMMUNICATION_DELIVERY => 'communications',
        RetentionExpiry::ERASURE_CASE => 'erasure',
        self::STORAGE_ORPHAN => 'storage',
        self::STUDENT_ATTENDANCE => 'student',
        self::STUDENT_ROLLOVER_ITEM => 'student',
        self::STUDENT_GUARDIAN_RELATIONSHIP => 'student',
        self::STUDENT_CORE => 'student',
        self::STUDENT_LIBRARY_LOAN => 'student',
        self::STUDENT_TRANSPORT_ASSIGNMENT => 'student',
        self::STUDENT_HOSTEL_RESIDENCY => 'student',
        // E21.3C: Admissions and Guardian records are Student-linked SIS data.
        self::ADMISSION_APPLICATION => 'student',
        self::GUARDIAN_RECORD => 'student',
        // E21.3D: School teaching evidence, not Student- or Employee-rooted: one
        // deliberate new family (`academic`), keeping the label far below its ceiling.
        self::CURRICULUM_DELIVERY => 'academic',
        self::ATTENDANCE_SESSION => 'academic',
        self::TIMETABLE_ENTRY => 'academic',
        self::LEARNING_CONTENT => 'academic',
        self::ASSIGNMENT => 'academic',
        // E21.3E: never-sent communication residuals stay in the communications family;
        // ended API credentials join authority; operational module residuals form one
        // deliberate family (`operations`), keeping the label far below its ceiling.
        self::COMMUNICATION_NEVER_SENT => 'communications',
        self::COMMUNICATION_EMPTY_THREAD => 'communications',
        RetentionExpiry::SCHOOL_API_CREDENTIAL => 'authority',
        self::DRIVER_ASSIGNMENT => 'operations',
        self::VISITOR_VISIT => 'operations',
        self::AUTOMATION_EXECUTION => 'operations',
        // E21.3B: an invitation is an offer of account authority; it joins the authority family.
        self::PORTAL_INVITATION => 'authority',
        self::EMPLOYEE_ANCILLARY => 'employee',
        self::PAYROLL_EMPLOYEE_RECORD => 'employee',
        self::EMPLOYEE_EVIDENCE => 'employee',
        // E21.3F: payroll evidence is D9 employment evidence; no new family.
        self::PAYROLL_EVIDENCE => 'employee',
        self::PAYROLL_RUN => 'employee',
        // HRX.6: Leave and Staff Attendance evidence is D9 employment evidence; no new family.
        self::LEAVE_EVIDENCE => 'employee',
        self::STAFF_ATTENDANCE_EVIDENCE => 'employee',
        self::FINANCE_UNIT => 'finance',
    ];

    /** @return list<string> every category (logs carry these) */
    public static function categories(): array
    {
        return array_keys(self::FAMILIES);
    }

    /** @return list<string> the metric's closed `operation` values */
    public static function families(): array
    {
        return array_values(array_unique(self::FAMILIES));
    }

    public static function family(string $category): string
    {
        return self::FAMILIES[$category] ?? throw new \InvalidArgumentException("Unknown retention category: {$category}");
    }

    /**
     * @param  array<string, int>  $counts  outcome => count (`errors` maps to `error`)
     */
    public static function record(MetricsRecorder $metrics, string $category, array $counts): void
    {
        foreach ($counts as $outcome => $count) {
            $outcome = $outcome === 'errors' ? 'error' : $outcome;

            if ($count > 0 && in_array($outcome, self::OUTCOMES, true)) {
                $metrics->counter('lycenza_retention_rows_total', $count, ['operation' => self::family($category), 'outcome' => $outcome]);
            }
        }
    }
}
