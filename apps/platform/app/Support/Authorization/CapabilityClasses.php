<?php

namespace App\Support\Authorization;

/**
 * SR.2 (ADR 0071 §6, §7, Appendix A): the closed, code-owned classification
 * of every `school` capability, and the class-scoped GRANT RIGHTS.
 *
 * - Classes describe what a capability reaches (closed set, CLASSES). They
 *   are metadata for review, audit (`role_grant_refused` uncovered classes,
 *   `role_assigned` sensitive-grant classes) and the SR.3 catalogue display
 *   -- never an authorization input.
 * - A grant right (`school.roles.grant.*`, class `authority`) lets its holder
 *   GRANT a role carrying a covered capability without holding that
 *   capability. It confers no data access: no module checks a grant-right key
 *   for anything but role-grant authority (an architecture guard pins this).
 *   A grant right is never itself covered, never covers an authority,
 *   legal-gated or owned-scope capability, and covers exactly the keys below.
 * - The same coverage is stored in `capabilities.grant_right` (seeded from
 *   GRANT_RIGHTS through the admin connection) so the database grantor
 *   trigger can read it; a test pins that the two agree.
 *
 * Namespace never decides a class (ADR 0071 §7). A new `school` capability
 * must be classified here, or the exhaustive classification test fails.
 */
final class CapabilityClasses
{
    public const CLASS_SET = [
        'operational', 'sensitive', 'children', 'financial', 'hr', 'hr-sensitive',
        'payroll-sensitive', 'authority', 'legal-gated', 'owned-scope',
    ];

    public const GRANT_HR = 'school.roles.grant.hr';

    public const GRANT_HR_SENSITIVE = 'school.roles.grant.hr_sensitive';

    public const GRANT_PAYROLL_SENSITIVE = 'school.roles.grant.payroll_sensitive';

    /** The v1 grant rights (ADR 0071 §6.1). No other exists. */
    public const GRANT_RIGHT_KEYS = [self::GRANT_HR, self::GRANT_HR_SENSITIVE, self::GRANT_PAYROLL_SENSITIVE];

    /** Covered capability => the grant right that covers it (ADR 0071 §6.1, Appendix A). */
    public const GRANT_RIGHTS = [
        // school.roles.grant.hr -- HR administration (15 keys)
        'hr.categories.view' => self::GRANT_HR,
        'hr.categories.manage' => self::GRANT_HR,
        'hr.departments.view' => self::GRANT_HR,
        'hr.departments.manage' => self::GRANT_HR,
        'hr.positions.view' => self::GRANT_HR,
        'hr.positions.manage' => self::GRANT_HR,
        'hr.employees.assignments.view' => self::GRANT_HR,
        'hr.employees.assignments.manage' => self::GRANT_HR,
        'hr.employees.qualifications.view' => self::GRANT_HR,
        'hr.employees.qualifications.manage' => self::GRANT_HR,
        'hr.employees.documents.view' => self::GRANT_HR,
        'hr.employees.documents.manage' => self::GRANT_HR,
        'hr.employees.notes.view' => self::GRANT_HR,
        'hr.employees.notes.manage' => self::GRANT_HR,
        'hr.employees.personal.manage' => self::GRANT_HR,
        // school.roles.grant.hr_sensitive -- Highly Sensitive Employee data (2 keys)
        'hr.employees.sensitive.view' => self::GRANT_HR_SENSITIVE,
        'hr.employees.sensitive.manage' => self::GRANT_HR_SENSITIVE,
        // school.roles.grant.payroll_sensitive -- compensation amounts and run results (2 keys)
        'payroll.compensation.sensitive.view' => self::GRANT_PAYROLL_SENSITIVE,
        'payroll.compensation.sensitive.manage' => self::GRANT_PAYROLL_SENSITIVE,
    ];

    /** Every `school` capability => its classes (ADR 0071 Appendix A + the three grant rights). */
    public const CLASSES = [
        'academics.structure.manage' => ['operational', 'sensitive'],
        'academics.structure.view' => ['operational', 'sensitive'],
        'academics.subjects.manage' => ['operational', 'sensitive'],
        'academics.subjects.view' => ['operational', 'sensitive'],
        'academics.years.manage' => ['operational', 'sensitive'],
        'academics.years.view' => ['operational', 'sensitive'],
        'admissions.manage' => ['children'],
        'admissions.view' => ['children'],
        'analytics.export' => ['legal-gated', 'sensitive'],
        'analytics.view' => ['sensitive'],
        'attendance.manage' => ['children'],
        'attendance.teacher' => ['owned-scope'],
        'attendance.view' => ['children'],
        'automation.manage' => ['authority', 'sensitive'],
        'automation.view' => ['sensitive'],
        'canteen.directory.manage' => ['operational'],
        'canteen.directory.view' => ['operational'],
        'canteen.orders.manage' => ['children', 'financial'],
        'canteen.orders.view' => ['children', 'financial'],
        'canteen.settings.manage' => ['financial'],
        'canteen.settings.view' => ['financial'],
        'communications.announce' => ['sensitive'],
        'communications.approve' => ['authority', 'sensitive'],
        'communications.audit.view' => ['sensitive'],
        'communications.conversations.guardians' => ['sensitive'],
        'communications.conversations.students' => ['legal-gated', 'sensitive'],
        'communications.emergency' => ['authority', 'sensitive'],
        'communications.manage' => ['sensitive'],
        'communications.reply' => ['sensitive'],
        'communications.send' => ['sensitive'],
        'communications.templates.manage' => ['sensitive'],
        'communications.view' => ['sensitive'],
        'curriculum.delivery.manage' => ['operational', 'sensitive'],
        'curriculum.delivery.teacher' => ['owned-scope', 'sensitive'],
        'curriculum.delivery.view' => ['operational', 'sensitive'],
        'enrollments.manage' => ['children'],
        'enrollments.rollovers.manage' => ['authority', 'children'],
        'enrollments.rollovers.view' => ['children'],
        'enrollments.view' => ['children'],
        'examinations.definitions.manage' => ['sensitive'],
        'examinations.definitions.view' => ['sensitive'],
        'examinations.grade_scales.manage' => ['sensitive'],
        'examinations.grade_scales.view' => ['sensitive'],
        'examinations.marks.correction.approve' => ['children', 'legal-gated', 'sensitive'],
        'examinations.marks.correction.request' => ['children', 'legal-gated', 'sensitive'],
        'examinations.marks.lock' => ['children', 'legal-gated', 'sensitive'],
        'examinations.marks.manage' => ['children', 'legal-gated', 'sensitive'],
        'examinations.marks.teacher' => ['legal-gated', 'owned-scope', 'sensitive'],
        'examinations.marks.view' => ['children', 'legal-gated', 'sensitive'],
        'examinations.papers.manage' => ['sensitive'],
        'examinations.papers.view' => ['sensitive'],
        'finance.accounts.manage' => ['financial'],
        'finance.charges.manage' => ['financial'],
        'finance.charges.view' => ['financial'],
        'finance.fee_assessments.run' => ['financial'],
        'finance.fee_concessions.approve' => ['financial'],
        'finance.fee_concessions.request' => ['financial'],
        'finance.fee_concessions.view' => ['financial'],
        'finance.fee_structures.manage' => ['financial'],
        'finance.fee_structures.view' => ['financial'],
        'finance.ledger.post' => ['financial'],
        'finance.ledger.reverse' => ['financial'],
        'finance.ledger.view' => ['financial'],
        'finance.payments.record' => ['financial'],
        'finance.payments.view' => ['financial'],
        'finance.periods.manage' => ['financial'],
        'guardians.manage' => ['children'],
        'guardians.view' => ['children'],
        'hostel.directory.manage' => ['operational'],
        'hostel.directory.view' => ['operational'],
        'hostel.residency.manage' => ['children'],
        'hostel.residency.view' => ['children'],
        'hr.categories.manage' => ['hr'],
        'hr.categories.view' => ['hr'],
        'hr.departments.manage' => ['hr'],
        'hr.departments.view' => ['hr'],
        'hr.employees.assignments.manage' => ['hr'],
        'hr.employees.assignments.view' => ['hr'],
        'hr.employees.documents.manage' => ['hr'],
        'hr.employees.documents.view' => ['hr'],
        'hr.employees.manage' => ['hr'],
        'hr.employees.notes.manage' => ['hr'],
        'hr.employees.notes.view' => ['hr'],
        'hr.employees.personal.manage' => ['hr'],
        'hr.employees.personal.view' => ['hr'],
        'hr.employees.qualifications.manage' => ['hr'],
        'hr.employees.qualifications.view' => ['hr'],
        'hr.employees.sensitive.manage' => ['hr-sensitive'],
        'hr.employees.sensitive.view' => ['hr-sensitive'],
        'hr.employees.view' => ['hr'],
        'hr.leave.approve' => ['hr'],
        'hr.leave.configure' => ['hr'],
        'hr.leave.manage' => ['hr'],
        'hr.leave.self' => ['owned-scope'],
        'hr.leave.view' => ['hr'],
        'hr.positions.manage' => ['hr'],
        'hr.positions.view' => ['hr'],
        'hr.staff_attendance.manage' => ['hr'],
        'hr.staff_attendance.self' => ['owned-scope'],
        'hr.staff_attendance.view' => ['hr'],
        'integrations.api_clients.manage' => ['authority', 'sensitive'],
        'integrations.api_clients.view' => ['sensitive'],
        'integrations.webhooks.manage' => ['authority', 'sensitive'],
        'integrations.webhooks.view' => ['sensitive'],
        'inventory.directory.manage' => ['operational'],
        'inventory.directory.view' => ['operational'],
        'inventory.stock.manage' => ['operational'],
        'inventory.stock.view' => ['operational'],
        'library.catalogue.manage' => ['operational'],
        'library.catalogue.view' => ['operational'],
        'library.circulation.manage' => ['children'],
        'library.circulation.view' => ['children'],
        'library.fines.manage' => ['children', 'financial'],
        'library.fines.view' => ['children', 'financial'],
        'library.fines.void' => ['children', 'financial'],
        'lms.assignments.manage' => ['children', 'sensitive'],
        'lms.assignments.teacher' => ['owned-scope'],
        'lms.assignments.view' => ['children', 'sensitive'],
        'lms.content.manage' => ['sensitive'],
        'lms.content.teacher' => ['owned-scope'],
        'lms.content.view' => ['sensitive'],
        'payroll.accounting.manage' => ['financial'],
        'payroll.compensation.sensitive.manage' => ['financial', 'payroll-sensitive'],
        'payroll.compensation.sensitive.view' => ['financial', 'payroll-sensitive'],
        'payroll.compensation.view' => ['financial'],
        'payroll.payslips.self' => ['owned-scope'],
        'payroll.periods.manage' => ['financial'],
        'payroll.runs.approve' => ['financial'],
        'payroll.runs.post' => ['financial'],
        'payroll.runs.prepare' => ['financial'],
        'payroll.runs.reverse' => ['financial'],
        'payroll.runs.view' => ['financial'],
        'payroll.statutory.exports.generate' => ['financial', 'legal-gated'],
        'payroll.statutory.identifiers.manage' => ['financial', 'legal-gated'],
        'payroll.statutory.identifiers.view' => ['financial', 'legal-gated'],
        'payroll.statutory.manage' => ['financial', 'legal-gated'],
        'payroll.statutory.view' => ['financial', 'legal-gated'],
        'payroll.structures.manage' => ['financial'],
        'payroll.structures.view' => ['financial'],
        'school.audit.view' => ['sensitive'],
        'school.campuses.manage' => ['authority'],
        'school.campuses.view' => ['operational'],
        'school.domains.manage' => ['authority'],
        'school.domains.view' => ['operational'],
        'school.members.manage' => ['authority'],
        'school.members.view' => ['operational'],
        'school.profile.manage' => ['authority'],
        'school.profile.view' => ['operational'],
        'school.roles.manage' => ['authority'],
        'school.roles.view' => ['sensitive'],
        'school.settings.manage' => ['authority'],
        'school.settings.view' => ['operational'],
        'students.manage' => ['children'],
        'students.processing_authorizations.manage' => ['authority', 'children'],
        'students.processing_authorizations.view' => ['children'],
        'students.view' => ['children'],
        'syllabus.manage' => ['operational', 'sensitive'],
        'syllabus.view' => ['operational', 'sensitive'],
        'teaching.assignments.manage' => ['authority', 'sensitive'],
        'teaching.assignments.view' => ['sensitive'],
        'timetable.periods.manage' => ['operational', 'sensitive'],
        'timetable.periods.view' => ['operational', 'sensitive'],
        'timetable.schedule.manage' => ['operational', 'sensitive'],
        'timetable.schedule.view' => ['operational', 'sensitive'],
        'transport.assignments.manage' => ['children'],
        'transport.assignments.view' => ['children'],
        'transport.routes.manage' => ['operational'],
        'transport.routes.view' => ['operational'],
        'transport.vehicles.manage' => ['operational'],
        'transport.vehicles.view' => ['operational'],
        'visitor.directory.manage' => ['operational', 'sensitive'],
        'visitor.directory.view' => ['operational', 'sensitive'],
        'visitor.visits.manage' => ['operational', 'sensitive'],
        'visitor.visits.view' => ['operational', 'sensitive'],
        // SR.2: the grant rights themselves (authority; never covered).
        'school.roles.grant.hr' => ['authority'],
        'school.roles.grant.hr_sensitive' => ['authority'],
        'school.roles.grant.payroll_sensitive' => ['authority'],
    ];

    /** @return list<string> */
    public static function of(string $capability): array
    {
        return self::CLASSES[$capability] ?? [];
    }

    /**
     * The sorted union of the classes of $capabilities.
     *
     * @param  iterable<string>  $capabilities
     * @return list<string>
     */
    public static function union(iterable $capabilities): array
    {
        $classes = [];
        foreach ($capabilities as $capability) {
            foreach (self::of($capability) as $class) {
                $classes[$class] = true;
            }
        }
        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    public static function grantRightFor(string $capability): ?string
    {
        return self::GRANT_RIGHTS[$capability] ?? null;
    }
}
