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
            'sharedLookups' => 'The stock pages\' active Item lookup (the School-wide catalogue: id, code, name, unit) answers a Campus-scoped stock.manage holder; the Item/Location directory (inventory.directory.*) stays School-only. A movement is visible only when every Location it touches is in scope; a transfer needs scope over both Locations.',
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
    public const WHOLE_SCHOOL_OPERATIONS = [];

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
        'data-health' => [
            'timetable.schedule.view' => 'Data Health is a School-wide report: its "Sections without a schedule" check lists every Section of the School, so it opens only on whole-School authority.',
        ],
    ];

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
