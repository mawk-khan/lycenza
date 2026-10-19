<?php

namespace Tests\Feature\Authorization\Campus;

use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Models\Role;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Authorization\CapabilityClasses;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.7D-0 (ADR 0076 §36, owner decision D0-10): adopting a capability for
 * Campus scope can make EXISTING Campus-scoped role grants newly effective
 * -- intentionally. So every adoption must report its impact, and this
 * snapshot is that report: which system School role templates hold an
 * adopted key, and what each Campus-scopable template then confers in a
 * Campus. Adding a key to CampusScopeCatalogue::ADOPTED (or changing a role
 * template) fails here until the snapshot -- and the adoption phase's report
 * -- says what changed.
 *
 * High-risk families need explicit owner approval before adoption, and
 * marks are not adoptable at all for now (D0-10).
 */
class CampusAdoptionImpactTest extends TestCase
{
    /**
     * System School role template => the adopted keys it holds (ORG.7A/7B
     * baseline; ORG.7D-1 D1-B: principal and school_admin gain
     * timetable.schedule.view/.manage, D1-C attendance.view/.manage, D1-D
     * curriculum.delivery.view/.manage, D1-E lms.content/.assignments
     * view/manage, D1-F examinations.papers.view/.manage -- only principal
     * is Campus-scopable; the teacher role's *.teacher keys and every
     * examinations.marks.* key are not adopted). ORG.7D-3: hostel_warden,
     * principal and school_admin gain hostel.residency.view/.manage, and
     * hostel_warden becomes Campus-scopable; canteen_operator, principal
     * and school_admin gain canteen.orders.view/.manage, and canteen_operator
     * becomes Campus-scopable; admissions_officer, principal and
     * school_admin gain admissions.view/.manage (pre-conversion only), and
     * admissions_officer becomes Campus-scopable.
     */
    private const HOLDERS = [
        'admissions_officer' => ['admissions.manage', 'admissions.view'],
        'canteen_operator' => ['canteen.orders.manage', 'canteen.orders.view'],
        'front_office' => ['visitor.visits.manage', 'visitor.visits.view'],
        'hostel_warden' => ['hostel.residency.manage', 'hostel.residency.view'],
        'librarian' => ['library.circulation.manage', 'library.circulation.view'],
        'principal' => ['admissions.manage', 'admissions.view', 'attendance.manage', 'attendance.view', 'canteen.orders.manage', 'canteen.orders.view', 'curriculum.delivery.manage', 'curriculum.delivery.view', 'examinations.papers.manage', 'examinations.papers.view', 'hostel.residency.manage', 'hostel.residency.view', 'inventory.stock.manage', 'inventory.stock.view', 'library.circulation.manage', 'library.circulation.view', 'lms.assignments.manage', 'lms.assignments.view', 'lms.content.manage', 'lms.content.view', 'timetable.schedule.manage', 'timetable.schedule.view', 'transport.assignments.manage', 'transport.assignments.view', 'transport.routes.view', 'visitor.visits.manage', 'visitor.visits.view'],
        'school_admin' => ['admissions.manage', 'admissions.view', 'attendance.manage', 'attendance.view', 'canteen.orders.manage', 'canteen.orders.view', 'curriculum.delivery.manage', 'curriculum.delivery.view', 'examinations.papers.manage', 'examinations.papers.view', 'hostel.residency.manage', 'hostel.residency.view', 'inventory.stock.manage', 'inventory.stock.view', 'library.circulation.manage', 'library.circulation.view', 'lms.assignments.manage', 'lms.assignments.view', 'lms.content.manage', 'lms.content.view', 'timetable.schedule.manage', 'timetable.schedule.view', 'transport.assignments.manage', 'transport.assignments.view', 'transport.routes.view', 'visitor.visits.manage', 'visitor.visits.view'],
        'stores_officer' => ['inventory.stock.manage', 'inventory.stock.view'],
        'transport_coordinator' => ['transport.assignments.manage', 'transport.assignments.view', 'transport.routes.view'],
    ];

    /** The templates a whole-School administrator may grant for one Campus. */
    private const CAMPUS_SCOPABLE = ['admissions_officer', 'canteen_operator', 'front_office', 'hostel_warden', 'librarian', 'principal', 'stores_officer', 'transport_coordinator'];

    /**
     * ORG.7D-5 (ADR 0076 §41, owner decisions): an authorized circulation or
     * rider write may produce a bounded, audited financial effect through its
     * own domain workflow -- approved as HIGH-RISK side effects, never as
     * Fees, Payments or ledger authority.
     */
    private const OWNER_APPROVED_SIDE_EFFECTS = [
        'library.circulation.manage' => 'Check-in assesses the overdue fine for that loan (LibraryFineService via FeeSourceChargeService).',
        'transport.assignments.manage' => 'A rider assignment records the Transport fee intent for the active year (TransportFeeSelectionService); ending withdraws future intent only.',
    ];

    /** No Campus-scopable template holding an approved side-effect key may hold one of these. */
    private const FINANCIAL_AUTHORITY_PREFIXES = ['fees.', 'finance.', 'payments.'];

    /** Families that need an explicit owner approval (recorded here, by key) before adoption. */
    private const HIGH_RISK = [
        'hr.employees.personal.', 'hr.employees.sensitive.', 'hr.employees.documents.', 'hr.employees.notes.',
        'hr.employees.qualifications.', 'payroll.', 'finance.', 'school.', 'integrations.', 'capabilities.',
    ];

    /** Capability classes (CapabilityClasses) that make an adopted key high-risk wherever its prefix is. */
    private const HIGH_RISK_CLASSES = ['authority', 'financial'];

    /**
     * Owner-approved high-risk adoptions. ORG.7D-3 (ADR 0076 §39.3): the owner
     * authorized Canteen order adoption; fulfilment's charge is Canteen's
     * bounded side effect and confers no Fees, Payments or ledger authority.
     */
    private const OWNER_APPROVED_HIGH_RISK = ['canteen.orders.manage', 'canteen.orders.view'];

    /**
     * ORG.7D-1 (ADR 0076 §37, D1-3, D1-10, D1-6): never adoptable in this
     * wave -- Student marks and results, School-wide academic
     * configuration, and the owned teacher keys (TeachingOwnership-scoped).
     */
    private const PROHIBITED_PREFIXES = [
        // Marks / results (D1-10): no prefix of these may be adopted.
        'examinations.marks.', 'examinations.results.', 'examinations.report_cards.', 'results.', 'report_cards.', 'gradebook.',
        // School-wide academic configuration (D1-3, §9, §20).
        'academics.', 'syllabus.', 'timetable.periods.', 'examinations.definitions.', 'examinations.grade_scales.', 'school.campuses.',
        // Student / SIS (ORG.7D-2). Admissions PRE-CONVERSION was authorized
        // by ORG.7D-3 (conversion and Applicant identity stay whole-School).
        'students.', 'enrollments.', 'guardians.',
    ];

    #[Test]
    public function the_role_templates_holding_adopted_keys_are_reported(): void
    {
        $holders = [];
        $scopable = [];
        $roles = Role::query()->where('scope', Role::SCOPE_SCHOOL)->where('is_system', true)->whereNull('retired_at')->with('capabilities')->orderBy('key')->get();
        foreach ($roles as $role) {
            $adopted = CampusScopeCatalogue::adoptedAmong($role->capabilities->pluck('key')->sort()->values()->all());
            if ($adopted !== []) {
                $holders[$role->key] = $adopted;
            }
            if (StaffRoleCatalog::campusScopable($role)) {
                $scopable[] = $role->key;
            }
        }

        $this->assertSame(self::HOLDERS, $holders, 'A Campus adoption changed which role templates hold adopted keys: report the new keys, their templates and the Campus-scoped grants that gain them (ADR 0076 §36), then update this snapshot.');
        $this->assertSame(self::CAMPUS_SCOPABLE, $scopable, 'A Campus adoption changed which templates may be granted for one Campus: report it, then update this snapshot.');
    }

    #[Test]
    public function high_risk_families_need_owner_approval_and_marks_are_not_adoptable(): void
    {
        foreach (CampusScopeCatalogue::capabilities() as $capability) {
            $this->assertStringStartsNotWith('examinations.marks.', $capability, 'Marks are not authorized for Campus adoption (D0-10).');
            foreach (self::PROHIBITED_PREFIXES as $prefix) {
                $this->assertStringStartsNotWith($prefix, $capability, "{$capability} belongs to a family ORG.7D-1 keeps whole-School or out of Campus scope (ADR 0076 §37).");
            }
            $this->assertStringEndsNotWith('.teacher', $capability, 'Teacher keys stay TeachingOwnership-scoped; a Campus scope never replaces ownership (D1-6).');
            $highRisk = str_ends_with($capability, '.approve') || array_intersect(CapabilityClasses::of($capability), self::HIGH_RISK_CLASSES) !== [];
            foreach (self::HIGH_RISK as $prefix) {
                $highRisk = $highRisk || str_starts_with($capability, $prefix);
            }
            if ($highRisk) {
                $this->assertContains($capability, self::OWNER_APPROVED_HIGH_RISK, "{$capability} is a high-risk family: adoption needs an explicit owner approval recorded here.");
            }
        }
    }

    #[Test]
    public function owner_approved_side_effects_are_adopted_and_confer_no_financial_authority(): void
    {
        $roles = Role::query()->where('scope', Role::SCOPE_SCHOOL)->where('is_system', true)->whereNull('retired_at')->with('capabilities')->get();
        foreach (self::OWNER_APPROVED_SIDE_EFFECTS as $capability => $effect) {
            $this->assertContains($capability, CampusScopeCatalogue::capabilities(), "{$capability}: {$effect}");
            foreach ($roles as $role) {
                $keys = $role->capabilities->pluck('key')->all();
                if (! in_array($capability, $keys, true) || ! StaffRoleCatalog::campusScopable($role)) {
                    continue;
                }
                foreach ($keys as $key) {
                    foreach (self::FINANCIAL_AUTHORITY_PREFIXES as $prefix) {
                        $this->assertStringStartsNotWith($prefix, $key, "{$role->key} is Campus-scopable with {$capability} and must not also carry financial authority ({$key}).");
                    }
                }
            }
        }
    }
}
