<?php

namespace App\Support\Authorization\Campus;

/**
 * ORG.7A (ADR 0076 §11.3, §33): the ONE code-owned declaration of which
 * School capabilities have been ADOPTED for Campus scope, and by which
 * domain. A Campus-scoped role grant confers a capability only when it is
 * listed here (fail closed until adoption); every other capability of that
 * role confers nothing through the scoped grant.
 *
 * Adoption never happens because a table merely has a `campus_id`: a domain
 * is listed only after its read and write paths apply its CampusScope
 * (`CampusScopeAdoptionArchitectureTest` checks every adopted capability
 * against its domain's scoped paths). Each domain declares its policy for
 * School-wide (NULL-Campus) records and inactive Campuses.
 *
 * Never listed: ORG-C2 whole-School authority (access administration,
 * School configuration, integrations, School-wide academic configuration).
 */
final class CampusScopeCatalogue
{
    /**
     * Adopted domains and their declared policies.
     *
     * @var array<string, array{label: string, nullCampus: string, inactiveCampus: string, sharedLookups: string}>
     */
    public const DOMAINS = [
        'visitor' => [
            'label' => 'Visitor check-in and visits',
            'nullCampus' => 'None: every Visit has a Campus (visitor_visits.campus_id NOT NULL).',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing (no read, no new Visit, no check-out); whole-School holders keep every Visit.',
            'sharedLookups' => 'Owner-approved (ORG.7B): the check-in lookup of the School-wide Visitor directory (active Visitors: id, name, phone -- no other field without a privacy review) and the host lookup (active Employees: id and display name only, no HR authority; re-evaluated when HR adopts Campus scope) answer a Campus-scoped visits.manage holder. The directory itself (visitor.directory.*) stays School-only. A check-in conflict seen by a Campus-scoped holder is one generic refusal that never names where the other Visit is (it still reveals the Visitor is busy somewhere in the School).',
        ],
        'inventory' => [
            'label' => 'Inventory stock',
            'nullCampus' => 'A Location without a Campus is a School-wide store: whole-School scope only (never listed, read or moved by a Campus-scoped holder).',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing (no read, no movement); whole-School holders keep everything.',
            'sharedLookups' => 'The stock pages\' active Item lookup (the School-wide catalogue: id, code, name, unit) answers a Campus-scoped stock.manage holder; the Item/Location directory (inventory.directory.*) stays School-only. A movement is visible only when every Location it touches is in scope; a transfer needs scope over both Locations. ORG.9C-2: the issue form\'s Department lookup (active HR Departments: id, code, name) offers School-wide Departments and those on in-scope Campuses only; a write-off follows its Location\'s scope, re-read fresh.',
        ],
        'timetable' => [
            'label' => 'Timetable schedule',
            'nullCampus' => 'None: every class (timetable_entries.campus_id NOT NULL) takes its Offering\'s Campus, and its Section and Room are bound to that Campus by composite foreign keys. The bell schedule (timetable.periods.*) is School-wide configuration and stays whole-School.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; nothing new is scheduled, updated or reactivated on an inactive Campus by anyone (ActiveCampus); existing classes are kept and can still be deactivated by a whole-School scheduler.',
            'sharedLookups' => 'ORG.7D-1: the scheduling form\'s teacher lookup (active Employees: id and display name only, D0-6) and bell-schedule lookup (active Periods: id, code, name, times) answer a Campus-scoped schedule.manage holder; Offerings, Sections and Rooms are offered from in-scope Campuses only. A slot collision seen by a Campus-scoped holder is one generic refusal (TIMETABLE_SLOT_UNAVAILABLE) whether the other class is in scope or not; teacher, Section and Room slots stay unique School-wide. Data Health (a School-wide report) stays whole-School.',
        ],
        'attendance' => [
            'label' => 'Student attendance registers (administration)',
            'nullCampus' => 'None: a register stores its Campus when written (attendance_sessions.campus_id NOT NULL, composite-FK-bound to its Section and Offering; every record is bound to its register\'s Campus). A new register takes the Campus of the timetable class it is for. History keeps the Campus it was written on, even if the class later changes.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing (no register read, submission or correction); whole-School holders keep every register.',
            'sharedLookups' => 'None beyond the class\'s own Section roster (StudentEnrollmentRosterReadService, Section-bound: no Student Campus resolution, no Student search). The exceptions list and its Section filter are restricted by each record\'s stored Campus. Teacher Attendance (attendance.teacher) is not Campus-adopted: it stays TeachingOwnership-scoped.',
        ],
        'curriculum_delivery' => [
            'label' => 'Curriculum delivery (administration)',
            'nullCampus' => 'None: a delivery stores its Campus when started (curriculum_deliveries.campus_id NOT NULL, composite-FK-bound to its Section and Offering) and it never changes.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; whole-School holders keep every delivery.',
            'sharedLookups' => 'None: Offerings, Sections and Syllabus Units are offered and accepted only within scope (a Unit only through an in-scope Offering). The curriculum definition (syllabus.*) stays whole-School configuration, and teacher delivery (curriculum.delivery.teacher) is not Campus-adopted: it stays TeachingOwnership-scoped.',
        ],
        'lms' => [
            'label' => 'Learning content and assignments (administration)',
            'nullCampus' => 'None: Learning Content and Assignments take the Campus of their owning Subject Offering, which never changes after creation (their Section-audience rows pin the same Campus by composite foreign key).',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; whole-School holders keep everything.',
            'sharedLookups' => 'None: Offerings are offered and accepted only within scope. Documents attached to Learning Content or an Assignment follow the parent\'s Campus (LmsParentResourceAuthorization). No Student roster is read (no Submission, ADR 0039). Teacher LMS (lms.*.teacher) is not Campus-adopted: it stays TeachingOwnership-scoped.',
        ],
        'examination_papers' => [
            'label' => 'Examination papers',
            'nullCampus' => 'None: a paper stores its Campus when created (examination_papers.campus_id NOT NULL, from its Subject Offering, which never changes). The Examination itself (examinations.definitions.*) and grade scales are School-wide configuration and stay whole-School.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; whole-School holders keep every paper.',
            'sharedLookups' => 'The paper page reads its parent Examination (code, name, window, status: School-wide configuration) as context only; the Examination list stays whole-School, so a Campus-only holder reaches a paper page by its Examination\'s link or the API, not from navigation (recorded for an owner decision, ADR 0076 §37). Offerings are offered and accepted only within scope. Marks and results (examinations.marks.*) are not Campus-adopted.',
        ],
        'hostel' => [
            'label' => 'Hostel residency',
            'nullCampus' => 'None: every Hostel has a Campus (hostels.campus_id NOT NULL); its Rooms, Beds and residencies reach it by composite foreign keys, and it cannot change once the Hostel has Rooms. The Hostel/Room/Bed directory (hostel.directory.*) stays whole-School configuration.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; an inactive Campus has no current operational Students, so no new residency is offered or written for it by a Campus-scoped actor.',
            'sharedLookups' => 'ORG.7D-3: the resident picker is the Students-owned purpose lookup, given the actor\'s scope (current operational Students only: id, displayName, studentNumber). v1: a Campus-scoped actor places a Student only in a Hostel of the Student\'s CURRENT operational Campus, re-checked at write time; "already resident" and a lost race are one generic refusal (HOSTEL_RESIDENCY_UNAVAILABLE). Hostel fee intent pages and carry-forward stay whole-School (declared operations).',
        ],
        'canteen' => [
            'label' => 'Canteen orders',
            'nullCampus' => 'An order takes its Outlet\'s Campus (canteen_outlets.campus_id, fixed after creation). A School-wide (NULL-Campus) Outlet and its orders are whole-School only. The Outlet/Item directory and billing settings (canteen.directory.*, canteen.settings.*) stay whole-School configuration.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; an inactive Campus has no current operational Students, so a Campus-scoped actor places no new order there.',
            'sharedLookups' => 'ORG.7D-3: the Student picker is the Students-owned purpose lookup with the actor\'s scope (current operational Students: id, studentNumber, name); a Campus-scoped order needs a current operational Student OF the Outlet\'s Campus, re-checked when placed. The menu picker is the School-wide Item catalogue (code, name, price). Fulfilment issues stock from the Outlet\'s own location (FK-bound to its Campus) and creates the charge as Canteen\'s bounded side effect: no Inventory or Fees authority.',
        ],
        'admissions' => [
            'label' => 'Admission applications (before conversion)',
            'nullCampus' => 'None: every application has a Campus (admission_applications.campus_id NOT NULL, set at creation, never changed by any code path). An Applicant has no Campus and is reached by a Campus-scoped actor only through an in-scope application.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; whole-School holders keep every application.',
            'sharedLookups' => 'None. Whole-School only (declared operations): the Applicant directory, page and create, starting an application (it needs the Applicant page), both Guardian searches (name, and the contact lookup), conversion to Student, and the admission fee head and fee provenance. The application page shows a Campus-scoped viewer no Applicant link, conversion form or fee panel. The Student purpose lookup is not used: an applicant is not yet a Student.',
        ],
        'library' => [
            'label' => 'Library circulation',
            'nullCampus' => 'A School-shared (NULL-Campus) Copy, and every loan of one, is whole-School only. A loan keeps its Copy\'s Campus at checkout (library_loans.campus_id, immutable; library_loans_campus_guard), and a Copy on an active loan never changes Campus (library_copies_campus_move_guard). The catalogue (library.catalogue.*) -- titles, Copies and Copy moves -- and fines (library.fines.*) stay whole-School.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; a Campus-scoped checkout or return on a Campus that deactivates concurrently waits for it or is refused (share-locked).',
            'sharedLookups' => 'ORG.7D-5: the borrower picker is the Students-owned purpose lookup with the actor\'s scope (current operational Students: id, studentNumber, name); the copy picker lists only lendable Copies on Campuses in scope (code, title). A Campus-scoped checkout needs the Copy\'s Campus in scope and the borrower current on THAT Campus, proven under lock; every refusal is LIBRARY_CHECKOUT_UNAVAILABLE. Returns and history follow the loan\'s frozen Campus, never the borrower\'s current one. Check-in\'s overdue fine is Library\'s bounded side effect (owner-approved, no Fees authority).',
        ],
        'transport' => [
            'label' => 'Transport routes and riders',
            'nullCampus' => 'A School-shared (NULL-Campus) route is whole-School only: never listed, opened or given riders by a Campus-scoped actor. A route\'s Campus is an active Campus of the School or none, and is fixed once the route has a Stop, a rider or a vehicle/driver assignment (TransportRouteService, under the route row lock). Route and Stop management (transport.routes.manage) and the fleet (transport.vehicles.*) stay whole-School.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing; a Campus-scoped rider assignment or end on a Campus that deactivates concurrently waits for it or is refused (share-locked).',
            'sharedLookups' => 'ORG.7D-5: the rider picker is the Students-owned purpose lookup with the actor\'s scope (current operational Students: id, studentNumber, name); the route and stop pickers list only Campus-bound routes in scope and their active stops (id, code/name, sequence). A Campus-scoped assignment needs the route Campus in scope and the Student current on THAT Campus, proven under lock; every refusal is TRANSPORT_ASSIGNMENT_UNAVAILABLE. Rider fee intent is Transport\'s bounded side effect (owner-approved, no Fees authority). Transport fees, fee selections, carry-forward and the route fee head stay whole-School (declared operations).',
        ],
        'hr' => [
            'label' => 'HR operational records (Directory, overview, core fields, onboarding, current assignments)',
            'nullCampus' => 'An Employee has no Campus (identity is School-wide); their operational Campuses are the active Campuses of their CURRENT assignments (CurrentEmployeeAssignments). A NULL-Campus (School-wide) assignment places nobody on a Campus and is whole-School only: never shown to, created, ended or changed by a Campus-scoped actor, and its presence makes the Employee\'s identity whole-School. An Employee with no current Campus assignment (unassigned, not yet started, ended, archived) is whole-School only.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing, and an inactive Campus places nobody. No onboarding or new assignment targets an inactive Campus (ActiveCampus); a Campus deactivating during a scoped write waits for it or refuses it (share-locked). History is never rewritten.',
            'sharedLookups' => 'ORG.7E-1: read-only reference labels for the assignment and onboarding forms -- active Positions (School-wide), active Departments that are School-wide or of an in-scope Campus, and the in-scope active Campuses -- never Department/Position/Category configuration. The manager picker lists only CURRENT assignments on in-scope Campuses. The Visitor host lookup stays School-wide and name-only (unchanged, recorded for later). Reaching an Employee through one in-scope assignment never discloses another Campus\'s assignment, placement or manager; the Profile (hr.employees.personal.view), history, personal/sensitive data, documents, notes, qualifications, pay, leave and staff attendance stay whole-School.',
        ],
        // ORG.7F-1 (ADR 0076 §45): SIS READ surfaces only.
        'students' => [
            'label' => 'Student Directory, operational overview, current placement, name edits and atomic onboarding',
            'nullCampus' => 'A Student has no Campus (identity is School-wide); their operational Campus is the Campus of their CURRENT placement (the canonical Student foundation rule, ADR 0076 §38: active Student, the active Enrollment in the School\'s active year, active Campus). A Student with no current operational Campus (unplaced, inactive, leaver, between placements, future-year only) is whole-School only. History never decides visibility: a former-Campus or draft/future-year placement places nobody on a Campus.',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing, and an inactive Campus places nobody (its Students are whole-School only until it is reactivated). History is never rewritten.',
            'sharedLookups' => 'None. The Campus Directory is a SIS surface under students.view (StudentAccess), never the other domains\' internal purpose-limited Student picker (ADR 0076 §38.5). The full Profile (date of birth, Guardians and their contacts, account link, history), Enrollment history, rollover, Guardian authority and every Student and Enrollment write except the name edit, atomic onboarding and the dual-scope Campus transfer (ORG.7F-3) stay whole-School; no Guardian data appears on a Campus surface (owner decision F1-D2). Onboarding offers only active Sections of the active year on an in-scope active Campus (read-only labels); the Student number is allocated by the server.',
        ],
        // ORG.7G-1 (ADR 0076 §50): Finance READ surfaces only.
        'finance' => [
            'label' => 'Finance reads (outstanding fees, charge history, payments received, receipts, debtor lookup) and ordinary payment entry',
            'nullCampus' => 'A Charge\'s financial Campus is the one STORED on it when it was assessed (charges.campus_id, frozen; ORG.7G-1A) -- never the Student\'s current Campus. A NULL-Campus Charge is whole-School only, never "every Campus". A Payment has no Campus: it is Campus-visible only when it has allocations and EVERY Charge it touches (original allocations and correction lines) carries the SAME non-NULL Campus in scope; a mixed-Campus or NULL-touching Payment, and its receipt and collections row, are whole-School only -- even for an actor holding every Campus it touches (owner decision G1-D4).',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing (no Charge, Payment, receipt or debtor read); whole-School holders keep every record, and Charges keep their stored Campus.',
            'sharedLookups' => 'The Finance debtor lookup (FinanceCampusAccess::debtors(): id, studentNumber, displayName only) answers a Campus-scoped finance.charges.view holder for Students with an OPEN Charge attributed in scope -- leavers and transferred Students included; never the SIS purpose-limited Student picker, the Campus Student Directory or the current Campus, and never Student Profile authority. An in-scope Charge shows its correct paid and outstanding amounts even when a contributing Payment is hidden (G1-D7): that Payment\'s amount on the Charge is shown without its id, receipt or other allocations. ORG.7G-2 (§51): payment ENTRY (finance.payments.record) records only when every allocated Charge has ONE non-NULL stored Campus in scope and active, under Charge locks (FinancePaymentAccess); a mixed or NULL-touching payment is refused with one generic answer; a replay is returned only while the stored Payment is still visible to the current scope. Fee configuration, fee and late-fee runs, manual Charges, concessions, payment corrections (reallocation, reversal, refund), the ledger and the full Student fee statement stay whole-School.',
        ],
    ];

    /**
     * ORG.7G-1 (ADR 0076 §50, owner decision G1-D10): whole-School Finance
     * authority. A role template holding ANY of these keys (an exact key, or a
     * family ending in '.') is never Campus-scopable
     * (StaffRoleCatalog::campusScopable()), whatever else it holds -- so
     * adopting a Finance read key makes `cashier` Campus-grantable while
     * `accountant` (ledger, accounts, fee configuration, runs, charge
     * management, concessions) stays whole-School. Decided by authority held,
     * never by role name.
     *
     * @var list<string>
     */
    public const CAMPUS_INCOMPATIBLE = [
        'finance.ledger.',
        'finance.accounts.manage',
        'finance.periods.manage',
        'finance.fee_structures.manage',
        'finance.fee_assessments.run',
        'finance.charges.manage',
        'finance.fee_concessions.',
    ];

    /**
     * Adopted capability => its domain.
     *
     * @var array<string, string>
     */
    public const ADOPTED = [
        'visitor.visits.view' => 'visitor',
        'visitor.visits.manage' => 'visitor',
        'inventory.stock.view' => 'inventory',
        'inventory.stock.manage' => 'inventory',
        'timetable.schedule.view' => 'timetable',
        'timetable.schedule.manage' => 'timetable',
        'attendance.view' => 'attendance',
        'attendance.manage' => 'attendance',
        'curriculum.delivery.view' => 'curriculum_delivery',
        'curriculum.delivery.manage' => 'curriculum_delivery',
        'lms.content.view' => 'lms',
        'lms.content.manage' => 'lms',
        'lms.assignments.view' => 'lms',
        'lms.assignments.manage' => 'lms',
        'examinations.papers.view' => 'examination_papers',
        'examinations.papers.manage' => 'examination_papers',
        'hostel.residency.view' => 'hostel',
        'hostel.residency.manage' => 'hostel',
        'canteen.orders.view' => 'canteen',
        'canteen.orders.manage' => 'canteen',
        'admissions.view' => 'admissions',
        'admissions.manage' => 'admissions',
        'library.circulation.view' => 'library',
        'library.circulation.manage' => 'library',
        'transport.routes.view' => 'transport',
        'transport.assignments.view' => 'transport',
        'transport.assignments.manage' => 'transport',
        // ORG.7E-1 (ADR 0076 §43): the operational HR subset (owner decisions E1-1, E1-2).
        'hr.employees.view' => 'hr',
        'hr.employees.assignments.view' => 'hr',
        'hr.employees.manage' => 'hr',
        'hr.employees.assignments.manage' => 'hr',
        // ORG.7F-1 (ADR 0076 §45): SIS reads only -- students.manage,
        // enrollments.manage, rollover, Guardian and processing-authorization
        // keys stay whole-School.
        'students.view' => 'students',
        'enrollments.view' => 'students',
        // ORG.7F-2 (ADR 0076 §46, owner decision F2-D10): the narrow writes --
        // Student names, and atomic onboarding (both keys) -- and ORG.7F-3
        // (§47, F3-D11): the dual-scope Campus transfer of the current placement.
        'students.manage' => 'students',
        'enrollments.manage' => 'students',
        // ORG.7G-1 (ADR 0076 §50, owner decisions G1-D1..G1-D9): Finance READS
        // only -- every Finance write, configuration, run, correction and the
        // ledger stay whole-School (and their holders non-scopable).
        'finance.charges.view' => 'finance',
        'finance.payments.view' => 'finance',
        // ORG.7G-2 (ADR 0076 §51, owner decisions G2-D1..G2-D8): ordinary
        // payment ENTRY only -- every allocated Charge of ONE non-NULL stored
        // Campus in scope (FinancePaymentAccess). Reallocation (which also needs
        // the protected finance.charges.manage), reversal, refund and every
        // other Finance write stay whole-School.
        'finance.payments.record' => 'finance',
    ];

    /**
     * ORG.7D-0 (ADR 0076 §36): declared WHOLE-SCHOOL-ONLY operations of an
     * adopted capability -- exact route name => the adopted key and why the
     * operation stays School-wide (e.g. a directory, an import, a
     * conversion). Such a route carries the ordinary whole-School floor
     * `capability:<key>` -- the `capability` Gate, which never counts a Campus
     * grant -- so a Campus-only holder can never enter it.
     *
     * Exact route names only: no pattern, no controller or namespace. A
     * whole-School floor on an adopted key anywhere else, and a stale or
     * malformed declaration, fail CI (CoverageGuard::adoptedWholeSchool()).
     * A controller-level whole-School check of an adopted key is still never
     * allowed (CampusScopeAdoptionArchitectureTest): the declaration is
     * expressed as the route floor, where the guard can see it.
     *
     * @var array<string, array{capability: string, reason: string}>
     */
    public const WHOLE_SCHOOL_OPERATIONS = [
        // ORG.7D-3 (ADR 0076 §39): Hostel fee intent is a School-wide view over
        // every residency, and carry-forward walks them all.
        'app.hostel-residency.fees' => ['capability' => 'hostel.residency.view', 'reason' => 'Hostel fee intent across every residency of the School (Fees-linked, School-wide).'],
        'app.hostel-residency.fees.carry-forward' => ['capability' => 'hostel.residency.manage', 'reason' => 'Next-year fee carry-forward walks every active residency of the School.'],
        'api.v1.schools.hostel-residency-assignments.fee-selections.index' => ['capability' => 'hostel.residency.view', 'reason' => 'A residency\'s recorded fee intent (Fees-linked).'],
        'api.v1.schools.hostel-fee-selections.carry-forward' => ['capability' => 'hostel.residency.manage', 'reason' => 'Next-year fee carry-forward walks every active residency of the School.'],
        // ORG.7D-3 (ADR 0076 §39): Admissions whole-School-only operations.
        'app.admissions.fee-head' => ['capability' => 'admissions.view', 'reason' => 'The School\'s admission fee head (Fees configuration).'],
        'app.admissions.fee-head.update' => ['capability' => 'admissions.manage', 'reason' => 'The School\'s admission fee head (Fees configuration).'],
        'app.admissions.applicants.index' => ['capability' => 'admissions.view', 'reason' => 'The School-wide Applicant directory: Applicants have no Campus.'],
        'app.admissions.applicants.create' => ['capability' => 'admissions.manage', 'reason' => 'Applicant identity is School-wide; there is no atomic applicant-plus-application path yet.'],
        'app.admissions.applicants.store' => ['capability' => 'admissions.manage', 'reason' => 'Applicant identity is School-wide; there is no atomic applicant-plus-application path yet.'],
        'app.admissions.applicants.show' => ['capability' => 'admissions.view', 'reason' => 'The Applicant page lists applications on every Campus.'],
        'app.admissions.applicants.edit' => ['capability' => 'admissions.manage', 'reason' => 'Applicant identity is School-wide (SIS 3.1 correction).'],
        'app.admissions.applicants.update' => ['capability' => 'admissions.manage', 'reason' => 'Applicant identity is School-wide (SIS 3.1 correction).'],
        'app.admissions.applicants.applications.create' => ['capability' => 'admissions.manage', 'reason' => 'Starting an application is reached from the whole-School Applicant page.'],
        'app.admissions.applicants.applications.store' => ['capability' => 'admissions.manage', 'reason' => 'Starting an application is reached from the whole-School Applicant page.'],
        'app.admissions.guardians.search' => ['capability' => 'admissions.manage', 'reason' => 'School-wide Guardian name search (conversion form).'],
        'app.admissions.guardians.candidates' => ['capability' => 'admissions.manage', 'reason' => 'School-wide Guardian contact lookup (a contact-to-identity oracle; conversion form).'],
        'app.admissions.convert' => ['capability' => 'admissions.manage', 'reason' => 'Conversion creates the Student, Guardian and enrollment (SIS): whole-School until the full SIS wave.'],
        'api.v1.schools.applicants.index' => ['capability' => 'admissions.view', 'reason' => 'The School-wide Applicant directory: Applicants have no Campus.'],
        'api.v1.schools.applicants.store' => ['capability' => 'admissions.manage', 'reason' => 'Applicant identity is School-wide.'],
        'api.v1.schools.applicants.show' => ['capability' => 'admissions.view', 'reason' => 'Applicant identity is School-wide.'],
        'api.v1.schools.applicants.applications.index' => ['capability' => 'admissions.view', 'reason' => 'An Applicant\'s application history spans every Campus.'],
        'api.v1.schools.admission-applications.store' => ['capability' => 'admissions.manage', 'reason' => 'Starting an application needs the whole-School Applicant identity.'],
        'api.v1.schools.admission-applications.convert' => ['capability' => 'admissions.manage', 'reason' => 'Conversion creates the Student, Guardian and enrollment (SIS): whole-School until the full SIS wave.'],
        'api.v1.schools.admission-fee-head.show' => ['capability' => 'admissions.view', 'reason' => 'The School\'s admission fee head (Fees configuration).'],
        'api.v1.schools.admission-fee-head.update' => ['capability' => 'admissions.manage', 'reason' => 'The School\'s admission fee head (Fees configuration).'],
        // ORG.7D-5 (ADR 0076 §41): Transport fee configuration and intent stay whole-School.
        'app.transport.fees' => ['capability' => 'transport.assignments.view', 'reason' => 'Transport fee intent across every rider of the School (Fees-linked, School-wide).'],
        'app.transport.fees.carry-forward' => ['capability' => 'transport.assignments.manage', 'reason' => 'Next-year fee carry-forward walks every active rider of the School.'],
        'api.v1.schools.transport-fee-selections.carry-forward' => ['capability' => 'transport.assignments.manage', 'reason' => 'Next-year fee carry-forward walks every active rider of the School.'],
        'api.v1.schools.transport-student-assignments.fee-selections.index' => ['capability' => 'transport.assignments.view', 'reason' => 'A rider\'s recorded fee intent (Fees-linked).'],
        'api.v1.schools.transport-routes.fee-head.show' => ['capability' => 'transport.routes.view', 'reason' => 'The route\'s fee head (Fees configuration).'],
        'api.v1.schools.admission-applications.fee-selection.show' => ['capability' => 'admissions.view', 'reason' => 'Recorded admission fee intent (Fees-linked provenance).'],
        // ORG.7E-1 (ADR 0076 §43): HR identity, account, lifecycle and bulk
        // operations of the adopted HR keys stay whole-School.
        'app.hr.employees.create' => ['capability' => 'hr.employees.manage', 'reason' => 'Identity-only create makes an Employee with no current assignment (no Campus); Campus actors onboard instead.'],
        'app.hr.employees.store' => ['capability' => 'hr.employees.manage', 'reason' => 'Identity-only create makes an Employee with no current assignment (no Campus); Campus actors onboard instead.'],
        'api.v1.schools.employees.store' => ['capability' => 'hr.employees.manage', 'reason' => 'Identity-only create (with an optional account link) makes an Employee with no Campus.'],
        'app.hr.employees.archive' => ['capability' => 'hr.employees.manage', 'reason' => 'Archive ends every current placement of the Employee on every Campus.'],
        'app.hr.employees.restore' => ['capability' => 'hr.employees.manage', 'reason' => 'Restore revives an Employee who is reachable on no Campus.'],
        'api.v1.schools.employees.archive' => ['capability' => 'hr.employees.manage', 'reason' => 'Archive ends every current placement of the Employee on every Campus.'],
        'api.v1.schools.employees.restore' => ['capability' => 'hr.employees.manage', 'reason' => 'Restore revives an Employee who is reachable on no Campus.'],
        'api.v1.schools.employees.link-user' => ['capability' => 'hr.employees.manage', 'reason' => 'Account linking is identity and security administration (ActingEmployee input).'],
        'api.v1.schools.employees.unlink-user' => ['capability' => 'hr.employees.manage', 'reason' => 'Account linking is identity and security administration (ActingEmployee input).'],
        'app.hr.employees.account-link.search' => ['capability' => 'hr.employees.manage', 'reason' => 'The School-wide sign-in account search of the account-link form.'],
        'app.hr.employees.account-link.store' => ['capability' => 'hr.employees.manage', 'reason' => 'Account linking is identity and security administration (ActingEmployee input).'],
        'app.hr.employees.account-link.destroy' => ['capability' => 'hr.employees.manage', 'reason' => 'Account linking is identity and security administration (ActingEmployee input).'],
        'app.hr.employees.import.create' => ['capability' => 'hr.employees.manage', 'reason' => 'Bulk import creates Employees, employments and assignments across the School.'],
        'app.hr.employees.import.store' => ['capability' => 'hr.employees.manage', 'reason' => 'Bulk import creates Employees, employments and assignments across the School.'],
        'api.v1.schools.employee-imports.store' => ['capability' => 'hr.employees.manage', 'reason' => 'Bulk import creates Employees, employments and assignments across the School.'],
        'app.hr.employees.employment-records.store' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'A new employment for an existing Employee (first hire, rehire, second contract) is School-wide employment administration.'],
        'app.hr.employees.employment-records.end' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'Ending employment is terminal and ends teaching ownership on every Campus.'],
        'app.hr.employees.employment-records.separate' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'Separation is terminal and ends teaching ownership on every Campus.'],
        'app.hr.employees.rehire' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'A former Employee has no current Campus: a Campus rehire would capture any former Employee.'],
        'api.v1.schools.employees.employment-records.store' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'A new employment for an existing Employee is School-wide employment administration.'],
        'api.v1.schools.employees.employment-records.update' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'Employment terms (type, category, probation) are School-wide employment administration.'],
        'api.v1.schools.employees.employment-records.end' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'Ending employment is terminal and ends teaching ownership on every Campus.'],
        'api.v1.schools.employees.employment-records.separate' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'Separation is terminal and ends teaching ownership on every Campus.'],
        'api.v1.schools.employees.rehire' => ['capability' => 'hr.employees.assignments.manage', 'reason' => 'A former Employee has no current Campus: a Campus rehire would capture any former Employee.'],
        // ORG.7F-1 (ADR 0076 §45): SIS whole-School-only reads of the adopted
        // students.view / enrollments.view.
        'app.students.show' => ['capability' => 'students.view', 'reason' => 'The full Student Profile: date of birth, Guardians, the account link and Enrollment history (Campus readers use the operational overview).'],
        'app.students.history' => ['capability' => 'students.view', 'reason' => 'The Student\'s audit history across every Campus and year.'],
        'api.v1.schools.students.show' => ['capability' => 'students.view', 'reason' => 'The full Student detail, including date of birth (Highly Sensitive).'],
        'api.v1.schools.students.guardians.index' => ['capability' => 'students.view', 'reason' => 'Guardian relationships are relationship-scoped School-wide identity, never Campus data.'],
        'api.v1.schools.students.enrollments.index' => ['capability' => 'enrollments.view', 'reason' => 'A Student\'s full placement history across years and Campuses.'],
        'app.enrollment-rollovers.index' => ['capability' => 'enrollments.view', 'reason' => 'Rollover plans move every Student of a year into the next one (School-wide lifecycle).'],
        'app.enrollment-rollovers.show' => ['capability' => 'enrollments.view', 'reason' => 'Rollover plans move every Student of a year into the next one (School-wide lifecycle).'],
        'api.v1.schools.enrollment-rollovers.index' => ['capability' => 'enrollments.view', 'reason' => 'Rollover plans move every Student of a year into the next one (School-wide lifecycle).'],
        'api.v1.schools.enrollment-rollovers.show' => ['capability' => 'enrollments.view', 'reason' => 'Rollover plans move every Student of a year into the next one (School-wide lifecycle).'],
        'api.v1.schools.enrollment-rollovers.items.index' => ['capability' => 'enrollments.view', 'reason' => 'Rollover items list every Student of the source year (School-wide lifecycle).'],
        // ORG.7F-2 (ADR 0076 §46): every students.manage / enrollments.manage
        // operation except the Campus name edit and atomic Campus onboarding.
        'api.v1.schools.enrollment-rollovers.items.update' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.mappings.store' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.mappings.update' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.resume' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.start' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.store' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.subject-mappings.destroy' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.subject-mappings.upsert' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollment-rollovers.validate' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'api.v1.schools.enrollments.cancel' => ['capability' => 'enrollments.manage', 'reason' => 'Ending a placement removes the Student\'s current Campus (lifecycle, owner decision F2: whole-School).'],
        'api.v1.schools.enrollments.complete' => ['capability' => 'enrollments.manage', 'reason' => 'Ending a placement removes the Student\'s current Campus (lifecycle, owner decision F2: whole-School).'],
        'api.v1.schools.enrollments.withdraw' => ['capability' => 'enrollments.manage', 'reason' => 'Ending a placement removes the Student\'s current Campus (lifecycle, owner decision F2: whole-School).'],
        'api.v1.schools.students.enrollments.store' => ['capability' => 'enrollments.manage', 'reason' => 'Enrolling an EXISTING Student could capture an unplaced, inactive or other-Campus Student (F-3); Campus actors onboard new Students instead.'],
        'app.enrollment-rollovers.bulk-decisions.apply' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.bulk-decisions.preview' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.create' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.items.update' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.mappings.store' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.mappings.update' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.resume' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.start' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.store' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.subject-mappings.destroy' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.subject-mappings.upsert' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollment-rollovers.validate' => ['capability' => 'enrollments.manage', 'reason' => 'Rollover moves every Student of a year into the next one (School-wide lifecycle; owner decision F2: whole-School).'],
        'app.enrollments.cancel' => ['capability' => 'enrollments.manage', 'reason' => 'Ending a placement removes the Student\'s current Campus (lifecycle, owner decision F2: whole-School).'],
        'app.enrollments.closed-year-placements.complete' => ['capability' => 'enrollments.manage', 'reason' => 'Closing a year\'s open placements walks every Campus (School-wide lifecycle).'],
        'app.enrollments.closed-year-placements.index' => ['capability' => 'enrollments.manage', 'reason' => 'Closing a year\'s open placements walks every Campus (School-wide lifecycle).'],
        'app.enrollments.complete' => ['capability' => 'enrollments.manage', 'reason' => 'Ending a placement removes the Student\'s current Campus (lifecycle, owner decision F2: whole-School).'],
        'app.enrollments.transfer.create' => ['capability' => 'enrollments.manage', 'reason' => 'Campus transfer is ORG.7F-3 (source AND destination in scope, placement freeze first); whole-School until then.'],
        'app.enrollments.transfer.store' => ['capability' => 'enrollments.manage', 'reason' => 'Campus transfer is ORG.7F-3 (source AND destination in scope, placement freeze first); whole-School until then.'],
        'app.enrollments.withdraw' => ['capability' => 'enrollments.manage', 'reason' => 'Ending a placement removes the Student\'s current Campus (lifecycle, owner decision F2: whole-School).'],
        'app.students.enrollments.create' => ['capability' => 'enrollments.manage', 'reason' => 'Enrolling an EXISTING Student could capture an unplaced, inactive or other-Campus Student (F-3); Campus actors onboard new Students instead.'],
        'app.students.enrollments.store' => ['capability' => 'enrollments.manage', 'reason' => 'Enrolling an EXISTING Student could capture an unplaced, inactive or other-Campus Student (F-3); Campus actors onboard new Students instead.'],
        'api.v1.schools.student-guardian-relationships.destroy' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'api.v1.schools.student-guardian-relationships.primary' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'api.v1.schools.student-guardian-relationships.update' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'api.v1.schools.students.guardians.store' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'api.v1.schools.students.status' => ['capability' => 'students.manage', 'reason' => 'Student status is lifecycle (owner decision F2-D4: whole-School).'],
        'api.v1.schools.students.store' => ['capability' => 'students.manage', 'reason' => 'Standalone create takes a typed Student number and makes a Student with no placement; Campus actors onboard atomically instead (F2-D5, F2-D8).'],
        'api.v1.schools.students.update' => ['capability' => 'students.manage', 'reason' => 'The full identity edit includes the Student number and date of birth (owner decisions F2-D3, F2-D5); Campus actors edit names only.'],
        'app.relationships.destroy' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.relationships.primary' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.relationships.update' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.students.account-link.destroy' => ['capability' => 'students.manage', 'reason' => 'Linking a sign-in account is identity and access administration.'],
        'app.students.account-link.search' => ['capability' => 'students.manage', 'reason' => 'Linking a sign-in account is identity and access administration.'],
        'app.students.account-link.store' => ['capability' => 'students.manage', 'reason' => 'Linking a sign-in account is identity and access administration.'],
        'app.students.create' => ['capability' => 'students.manage', 'reason' => 'Standalone create takes a typed Student number and makes a Student with no placement; Campus actors onboard atomically instead (F2-D5, F2-D8).'],
        'app.students.edit' => ['capability' => 'students.manage', 'reason' => 'The full identity edit includes the Student number and date of birth (owner decisions F2-D3, F2-D5); Campus actors edit names only.'],
        'app.students.guardians.candidates' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.students.guardians.create' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.students.guardians.link' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.students.guardians.search' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.students.guardians.store' => ['capability' => 'students.manage', 'reason' => 'Guardian relationships are School-wide, relationship-scoped identity (owner decision F2-D12).'],
        'app.students.status' => ['capability' => 'students.manage', 'reason' => 'Student status is lifecycle (owner decision F2-D4: whole-School).'],
        'app.students.store' => ['capability' => 'students.manage', 'reason' => 'Standalone create takes a typed Student number and makes a Student with no placement; Campus actors onboard atomically instead (F2-D5, F2-D8).'],
        'app.students.update' => ['capability' => 'students.manage', 'reason' => 'The full identity edit includes the Student number and date of birth (owner decisions F2-D3, F2-D5); Campus actors edit names only.'],
        // ORG.7G-1 (ADR 0076 §50): Finance reads that stay whole-School.
        'app.finance.fee-runs.index' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'app.finance.fee-runs.show' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'app.finance.late-fee-runs.index' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'app.finance.late-fee-runs.show' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'api.v1.schools.fee-assessment-runs.index' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'api.v1.schools.fee-assessment-runs.show' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'api.v1.schools.fee-assessment-runs.items' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'api.v1.schools.late-fee-runs.index' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'api.v1.schools.late-fee-runs.show' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'api.v1.schools.late-fee-runs.items' => ['capability' => 'finance.charges.view', 'reason' => 'Fee and late-fee runs are School-wide Finance operations (every Student of a structure or rule, across Campuses); a Campus-scoped reader sees each resulting Charge through the Campus-aware Charge pages.'],
        'app.finance.fee-statements.index' => ['capability' => 'finance.charges.view', 'reason' => 'The full Student fee statement mixes every Campus, whole-School (NULL-Campus) Charges and mixed-Campus Payments, so a Campus slice would misstate the Student\'s position (owner rule G1 §14: deferred); it also requires whole-School finance.payments.view.'],
        'app.finance.fee-statements.students.search' => ['capability' => 'finance.charges.view', 'reason' => 'The full Student fee statement mixes every Campus, whole-School (NULL-Campus) Charges and mixed-Campus Payments, so a Campus slice would misstate the Student\'s position (owner rule G1 §14: deferred); it also requires whole-School finance.payments.view.'],
        'app.finance.fee-statements.show' => ['capability' => 'finance.charges.view', 'reason' => 'The full Student fee statement mixes every Campus, whole-School (NULL-Campus) Charges and mixed-Campus Payments, so a Campus slice would misstate the Student\'s position (owner rule G1 §14: deferred); it also requires whole-School finance.payments.view.'],
        'api.v1.schools.students.fee-statement' => ['capability' => 'finance.charges.view', 'reason' => 'The full Student fee statement mixes every Campus, whole-School (NULL-Campus) Charges and mixed-Campus Payments, so a Campus slice would misstate the Student\'s position (owner rule G1 §14: deferred); it also requires whole-School finance.payments.view.'],
    ];

    /**
     * ORG.7D-1 (ADR 0076 §37): navigation hubs (StaffNavigation::HUBS) that
     * are WHOLE-SCHOOL reports yet open on an adopted capability among
     * others -- hub key => [adopted capability => why the hub stays
     * School-wide]. The hub's controller checks the key with the
     * whole-School Gate on purpose (its per-requirement flag), so a Campus
     * grant neither opens the hub nor shows its entry (NavigationBuilder
     * shows a Campus-held key only on an all-adopted entry).
     *
     * Only the hub's own controller is exempt, and only for the declared
     * keys; a stale or malformed declaration fails
     * CampusScopeAdoptionArchitectureTest.
     *
     * @var array<string, array<string, string>>
     */
    public const WHOLE_SCHOOL_HUBS = [
        // ORG.7E-1 (ADR 0076 §43): the HR hub also links HR configuration
        // (Departments, Positions, Categories) and import; Campus-scoped HR
        // actors land on the Staff directory entry instead.
        'hr' => [
            'hr.employees.view' => 'The HR hub groups whole-School HR configuration and import links; a Campus-scoped reader uses the Campus-aware Staff directory entry.',
            'hr.employees.manage' => 'The HR hub groups whole-School HR configuration and import links; a Campus-scoped reader uses the Campus-aware Staff directory entry.',
        ],
        // ORG.7G-1 (ADR 0076 §50): the Finance hub links fee configuration, runs,
        // concessions, the ledger, periods and statements; Campus-scoped Finance
        // readers land on the Outstanding fees and Payments received entries.
        'finance' => [
            'finance.charges.view' => 'The Finance hub groups whole-School fee configuration, runs, concessions, statements and ledger links; a Campus-scoped reader uses the Campus-aware Outstanding fees entry.',
            'finance.payments.view' => 'The Finance hub groups whole-School fee configuration, runs, concessions, statements and ledger links; a Campus-scoped reader uses the Campus-aware Payments received entry.',
            // ORG.7G-2 (§51).
            'finance.payments.record' => 'The Finance hub groups whole-School Finance links; a Campus-scoped recorder records from the Campus-aware Payments received page.',
        ],
        'data-health' => [
            'timetable.schedule.view' => 'Data Health is a School-wide report: its "Sections without a schedule" check lists every Section of the School, so it opens only on whole-School authority.',
            // ORG.7F-1 (ADR 0076 §45).
            'enrollments.view' => 'Data Health is a School-wide report: its placement checks list Students and placements of every Campus and year, so it opens only on whole-School authority.',
        ],
    ];

    /** ORG.7G-1: whether $capability is whole-School Finance authority (CAMPUS_INCOMPATIBLE). */
    public static function isCampusIncompatible(string $capability): bool
    {
        foreach (self::CAMPUS_INCOMPATIBLE as $key) {
            if ($capability === $key || (str_ends_with($key, '.') && str_starts_with($capability, $key))) {
                return true;
            }
        }

        return false;
    }

    public static function isAdopted(string $capability): bool
    {
        return isset(self::ADOPTED[$capability]);
    }

    /** @return list<string> */
    public static function capabilities(): array
    {
        return array_keys(self::ADOPTED);
    }

    /** @return list<string> the product-area labels of the adopted capabilities among $capabilities */
    public static function areasOf(array $capabilities): array
    {
        $areas = [];
        foreach (self::adoptedAmong($capabilities) as $capability) {
            $areas[self::ADOPTED[$capability]] = self::DOMAINS[self::ADOPTED[$capability]]['label'];
        }

        return array_values($areas);
    }

    /** @return list<string> the adopted capabilities among $capabilities */
    public static function adoptedAmong(array $capabilities): array
    {
        return array_values(array_filter($capabilities, fn (string $c): bool => self::isAdopted($c)));
    }
}
