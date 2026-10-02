<?php

namespace App\Support\Retention;

use App\Support\Observability\MetricsRecorder;

/**
 * E21 retention counters: `lycenza_retention_rows_total{operation, outcome}`.
 * Closed values only, counts only, never an identifier.
 *
 * E21.3A2: the `operation` label is a small, stable FAMILY (audit, email,
 * authority, communications, storage, student, employee, erasure,
 * finance), not one value per category: the category list kept growing
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

    /** E21.3B (E21.2G I2): ended portal invitations, 7 days after they ended. */
    public const PORTAL_INVITATION = 'portal_invitation';

    /** E21.2E (E21-D9) Employee categories; their units are Employees. */
    public const EMPLOYEE_ANCILLARY = 'employee_ancillary';

    public const PAYROLL_EMPLOYEE_RECORD = 'payroll_employee_record';

    public const EMPLOYEE_EVIDENCE = 'employee_evidence';

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
        // E21.3B: an invitation is an offer of account authority; it joins the authority family.
        self::PORTAL_INVITATION => 'authority',
        self::EMPLOYEE_ANCILLARY => 'employee',
        self::PAYROLL_EMPLOYEE_RECORD => 'employee',
        self::EMPLOYEE_EVIDENCE => 'employee',
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
