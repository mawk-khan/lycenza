<?php

namespace App\Support\Navigation;

use App\Support\Navigation\NavigationEntry as E;

/**
 * SWC.1 (ADR 0073 section 6): the ONE authoritative navigation catalogue
 * for signed-in pages. NavigationBuilder evaluates it per request, after
 * the School context is resolved, and sends only the entries the actor may
 * see. It is discoverability, never authorization: every destination
 * re-checks its own capability server-side (CLAUDE.md rule 6).
 *
 * Rules (enforced by Tests\Feature\Navigation\*):
 *   - an entry's requirement is exactly the capability its page checks
 *     (any-of where the page is a hub over several view capabilities);
 *   - existing capability keys only; no role key anywhere;
 *   - every route name resolves to a GET page that renders for a holder;
 *   - a gated surface (marks, statutory payroll, the production Guardian
 *     portal) never gets a primary entry, or only behind its existing
 *     availability guard.
 */
final class StaffNavigation
{
    /** Group order and labels (ADR 0073 section 6.3). */
    public const GROUPS = [
        'guardian-portal' => 'Guardian portal',
        'my-work' => 'My work',
        'school-setup' => 'School setup',
        'academics' => 'Academics',
        'people' => 'People',
        'finance' => 'Finance',
        'operations' => 'Operations',
        'communications' => 'Communications',
        'reports' => 'Reports',
        'administration' => 'Administration',
        'school-groups' => 'School Groups',
        'platform' => 'Platform',
        'account' => 'Account',
    ];

    /** Groups rendered by the account bar, never as a navigation group. */
    public const ACCOUNT_GROUP = 'account';

    /** @return list<E> */
    public static function entries(): array
    {
        return [
            // POR (ADR 0070): development only until POR-L1 (E46) -- the
            // availability guard re-applies PortalAvailability and a live
            // ActingGuardian, exactly as the portal routes do.
            new E('portal-communications', 'guardian-portal', 'Messages from the School', 'app.portal.communications.index', all: ['portal.communications.view'], availability: NavigationBuilder::AVAILABILITY_GUARDIAN_PORTAL),
            new E('portal-conversations', 'guardian-portal', 'Conversations', 'app.portal.conversations.index', all: ['portal.communications.view'], availability: NavigationBuilder::AVAILABILITY_GUARDIAN_PORTAL),
            new E('portal-attendance', 'guardian-portal', 'Attendance', 'app.portal.attendance.index', all: ['portal.attendance.view'], availability: NavigationBuilder::AVAILABILITY_GUARDIAN_PORTAL),
            new E('portal-fees', 'guardian-portal', 'Fees', 'app.portal.fees.index', all: ['portal.fees.view'], availability: NavigationBuilder::AVAILABILITY_GUARDIAN_PORTAL),

            // Own (self-service and teacher-owned) capabilities.
            new E('my-leave', 'my-work', 'My leave', 'app.my-leave.index', all: ['hr.leave.self']),
            new E('my-staff-attendance', 'my-work', 'My staff attendance', 'app.my-staff-attendance.index', all: ['hr.staff_attendance.self']),
            new E('my-payslips', 'my-work', 'My payslips', 'app.my-payslips.index', all: ['payroll.payslips.self']),
            new E('my-curriculum-delivery', 'my-work', 'My curriculum delivery', 'app.my-curriculum-delivery.index', all: ['curriculum.delivery.teacher']),
            new E('my-attendance', 'my-work', 'My class attendance', 'app.my-attendance.index', all: ['attendance.teacher']),
            new E('my-learning-content', 'my-work', 'My learning content', 'app.my-learning-content.index', all: ['lms.content.teacher']),
            new E('my-assignments', 'my-work', 'My assignments', 'app.my-assignments.index', all: ['lms.assignments.teacher']),

            new E('school-setup', 'school-setup', 'School setup', 'app.school-setup.index', all: ['school.profile.view']),
            // SWC.2 (ADR 0073 section 10): every Academic Structure screen,
            // under the capability that page itself checks.
            new E('campuses', 'school-setup', 'Campuses', 'app.school-setup.campuses', all: ['school.campuses.view']),
            new E('academic-years', 'school-setup', 'Academic years', 'app.school-setup.academic-years', all: ['academics.years.view']),
            new E('terms', 'school-setup', 'Terms', 'app.school-setup.terms', all: ['academics.years.view']),
            new E('grade-levels', 'school-setup', 'Grade levels', 'app.school-setup.grade-levels', all: ['academics.structure.view']),
            new E('sections', 'school-setup', 'Sections', 'app.school-setup.sections', all: ['academics.structure.view']),
            new E('departments', 'school-setup', 'Academic departments', 'app.school-setup.departments', all: ['academics.structure.view']),
            new E('rooms', 'school-setup', 'Rooms', 'app.school-setup.rooms', all: ['academics.structure.view']),
            new E('subjects', 'school-setup', 'Subjects', 'app.school-setup.subjects', all: ['academics.subjects.view']),
            new E('subject-offerings', 'school-setup', 'Subject offerings', 'app.subject-offerings.index', all: ['academics.subjects.view']),
            new E('elective-groups', 'school-setup', 'Elective groups', 'app.elective-groups.index', all: ['academics.subjects.view']),

            new E('timetable-schedule', 'academics', 'Timetable', 'app.timetable-schedule.index', all: ['timetable.schedule.view']),
            new E('timetable-periods', 'academics', 'Timetable periods', 'app.timetable-periods.index', all: ['timetable.periods.view']),
            new E('teaching-assignments', 'academics', 'Teaching assignments', 'app.teaching-assignments.index', all: ['teaching.assignments.view'], activeWhen: ['app.teaching-assignments.*', 'app.elective-teaching-assignments.*']),
            new E('attendance', 'academics', 'Attendance', 'app.attendance.index', all: ['attendance.view']),
            new E('syllabus', 'academics', 'Syllabus', 'app.syllabus.index', all: ['syllabus.view']),
            new E('curriculum-delivery', 'academics', 'Curriculum delivery', 'app.syllabus-delivery.index', all: ['curriculum.delivery.view']),
            // Definitions, papers and grade scales only. StudentMark has no
            // page and is refused outside local/testing (E35-E37, ADR 0073 6.4).
            new E('examinations', 'academics', 'Examinations', 'app.examinations.index', all: ['examinations.definitions.view']),
            new E('learning-content', 'academics', 'Learning content', 'app.learning-content.index', all: ['lms.content.view']),
            new E('assignments', 'academics', 'Assignments', 'app.assignments.index', all: ['lms.assignments.view']),

            new E('admissions', 'people', 'Admissions', 'app.admissions.index', all: ['admissions.view']),
            new E('students', 'people', 'Students', 'app.students.index', all: ['students.view']),
            new E('guardians', 'people', 'Guardians', 'app.guardians.index', all: ['guardians.view']),
            new E('enrollments', 'people', 'Enrollments', 'app.enrollments.index', all: ['enrollments.view']),
            // Phase 1B.7F: both capabilities, as the page requires.
            new E('enrollment-rollovers', 'people', 'Enrollment rollovers', 'app.enrollment-rollovers.index', all: ['enrollments.view', 'enrollments.rollovers.view']),
            // Hub pages: any one view capability shows the entry; the hub
            // hides the sub-areas the actor cannot see.
            new E('hr', 'people', 'HR', 'app.hr.index', any: ['hr.employees.view', 'hr.departments.view', 'hr.positions.view']),
            new E('leave', 'people', 'Leave', 'app.leave.requests', all: ['hr.leave.view'], activeWhen: ['app.leave.*']),
            new E('leave-approvals', 'people', 'Leave approvals', 'app.leave.approvals', all: ['hr.leave.approve'], activeWhen: ['app.leave.approvals*']),
            new E('staff-attendance', 'people', 'Staff attendance', 'app.staff-attendance.register', all: ['hr.staff_attendance.view'], activeWhen: ['app.staff-attendance.*']),

            new E('finance', 'finance', 'Finance', 'app.finance.index', any: ['finance.ledger.view', 'finance.charges.view', 'finance.payments.view']),
            // Statutory payroll stays a Payroll sub-link behind
            // payroll.statutory.view, which no role holds (E45).
            new E('payroll', 'finance', 'Payroll', 'app.payroll.index', any: ['payroll.structures.view', 'payroll.compensation.view', 'payroll.runs.view', 'payroll.accounting.manage']),

            new E('transport-routes', 'operations', 'Routes', 'app.transport.routes.index', all: ['transport.routes.view'], section: 'Transport'),
            new E('transport-vehicles', 'operations', 'Vehicles', 'app.transport.vehicles.index', all: ['transport.vehicles.view'], section: 'Transport'),
            new E('transport-operations', 'operations', 'Daily operations', 'app.transport.operations.index', all: ['transport.vehicles.view'], section: 'Transport'),
            new E('transport-assignments', 'operations', 'Student assignments', 'app.transport.assignments.index', all: ['transport.assignments.view'], section: 'Transport'),
            // SWC.3 (ADR 0073 section 19): OPF fee intent and carry-forward.
            new E('transport-fees', 'operations', 'Fees', 'app.transport.fees', all: ['transport.assignments.view'], section: 'Transport'),
            new E('hostels', 'operations', 'Hostels', 'app.hostels.index', all: ['hostel.directory.view'], section: 'Hostel', activeWhen: ['app.hostels.*', 'app.hostel-rooms.*']),
            new E('hostel-residency', 'operations', 'Residency', 'app.hostel-residency.index', all: ['hostel.residency.view'], section: 'Hostel'),
            new E('hostel-fees', 'operations', 'Fees', 'app.hostel-residency.fees', all: ['hostel.residency.view'], section: 'Hostel'),
            new E('library-catalogue', 'operations', 'Catalogue', 'app.library.titles.index', all: ['library.catalogue.view'], section: 'Library', activeWhen: ['app.library.titles.*']),
            new E('library-circulation', 'operations', 'Circulation', 'app.library.circulation.index', all: ['library.circulation.view'], section: 'Library'),
            // Production use of Library fines stays gated by E34 (ADR 0058); navigation changes no gate.
            new E('library-fine-policy', 'operations', 'Fine policy', 'app.library.fine-policy', all: ['library.fines.view'], section: 'Library'),
            new E('inventory-items', 'operations', 'Items', 'app.inventory-items.index', all: ['inventory.directory.view'], section: 'Inventory'),
            new E('inventory-locations', 'operations', 'Locations', 'app.inventory-locations.index', all: ['inventory.directory.view'], section: 'Inventory'),
            new E('inventory-stock', 'operations', 'Stock', 'app.inventory-stock.index', all: ['inventory.stock.view'], section: 'Inventory'),
            new E('canteen-outlets', 'operations', 'Outlets', 'app.canteen-outlets.index', all: ['canteen.directory.view'], section: 'Canteen'),
            new E('canteen-items', 'operations', 'Items', 'app.canteen-items.index', all: ['canteen.directory.view'], section: 'Canteen'),
            new E('canteen-orders', 'operations', 'Orders', 'app.canteen-orders.index', all: ['canteen.orders.view'], section: 'Canteen'),
            new E('canteen-settings', 'operations', 'Billing settings', 'app.canteen-settings.edit', all: ['canteen.settings.view'], section: 'Canteen', activeWhen: ['app.canteen-settings.*']),
            new E('visitor-directory', 'operations', 'Visitors', 'app.visitor.directory.index', all: ['visitor.directory.view'], section: 'Visitors'),
            new E('visitor-visits', 'operations', 'Visits', 'app.visitor.visits.index', all: ['visitor.visits.view'], section: 'Visitors'),

            new E('communications', 'communications', 'Communication Hub', 'app.communications.index', all: ['communications.view']),

            new E('analytics', 'reports', 'Curriculum coverage', 'app.analytics.curriculum-coverage', all: ['analytics.view']),
            new E('audit-log', 'reports', 'Audit log', 'app.compliance.audit-log', all: ['school.audit.view']),

            new E('school-settings', 'administration', 'School settings', 'app.settings.show', all: ['school.settings.view']),
            new E('staff-accounts', 'administration', 'Staff accounts', 'app.settings.staff.index', all: ['school.members.view']),
            new E('automation', 'administration', 'Automation', 'app.automation', all: ['automation.view']),
            new E('domains', 'administration', 'Custom domains', 'app.settings.domains.index', all: ['school.domains.view']),
            new E('api-clients', 'administration', 'API clients', 'app.integrations.api-clients.index', all: ['integrations.api_clients.view']),

            // Group authority (ADR 0045; rule 84) -- never School authority.
            new E('school-groups', 'school-groups', 'Your School Groups', 'app.groups.index', scope: E::SCOPE_GROUP, all: ['group.schools.view'], platformHostOnly: true),

            // Platform authority (ADR 0046/0047) -- never School authority.
            new E('platform-schools', 'platform', 'Schools', 'app.platform.schools.index', scope: E::SCOPE_PLATFORM, all: ['platform.schools.manage'], platformHostOnly: true),
            new E('platform-groups', 'platform', 'School Groups', 'app.platform.groups.index', scope: E::SCOPE_PLATFORM, all: ['platform.school_groups.view'], platformHostOnly: true),
            new E('platform-audit-log', 'platform', 'Platform audit log', 'app.platform.audit-log', scope: E::SCOPE_PLATFORM, all: ['platform.audit.view'], platformHostOnly: true),
            new E('platform-roles', 'platform', 'Platform roles', 'app.platform.roles.index', scope: E::SCOPE_PLATFORM, all: ['platform.role_grants.manage'], platformHostOnly: true),

            new E('account-security', 'account', 'Account security', 'app.account.security.show', scope: E::SCOPE_ACCOUNT),
            // Personal API tokens are platform-host only (SchoolHostSurface).
            new E('api-tokens', 'account', 'API tokens', 'app.account.api-tokens.index', scope: E::SCOPE_ACCOUNT, platformHostOnly: true),
        ];
    }
}
