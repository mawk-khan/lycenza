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
     * admissions_officer becomes Campus-scopable. ORG.7E-1 (owner decisions
     * E1-1/E1-2/E1-3): principal and school_admin gain hr.employees.view/
     * .manage; hr_officer gains hr.employees.view/.manage/.assignments.view/
     * .assignments.manage and becomes Campus-scopable; school_admin stays
     * whole-School only. ORG.7F-1 (owner decision F1-D7): principal and
     * school_admin gain the SIS READ keys students.view/enrollments.view;
     * principal@Campus gains reads only (school_admin stays whole-School).
     * ORG.7F-2 (F2-D10): both also hold the adopted students.manage /
     * enrollments.manage; a Campus grant reaches only the name edit and
     * atomic onboarding (every other operation is declared whole-School).
     * SIS 11.5 (owner decision 2026-10-31): admissions_officer, principal and
     * school_admin gain admissions.documents.view/.manage, scoped by the
     * application's own Campus like the rest of pre-conversion Admissions;
     * Student documents and verification stay whole-School.
     */
    private const HOLDERS = [
        'accountant' => ['finance.charges.view', 'finance.payments.record', 'finance.payments.view'],
        'admissions_officer' => ['admissions.documents.manage', 'admissions.documents.view', 'admissions.manage', 'admissions.view'],
        'canteen_operator' => ['canteen.orders.manage', 'canteen.orders.view'],
        'cashier' => ['finance.charges.view', 'finance.payments.record', 'finance.payments.view'],
        'front_office' => ['visitor.visits.manage', 'visitor.visits.view'],
        'hostel_warden' => ['hostel.residency.manage', 'hostel.residency.view'],
        'hr_officer' => ['hr.employees.assignments.manage', 'hr.employees.assignments.view', 'hr.employees.manage', 'hr.employees.view'],
        'librarian' => ['library.circulation.manage', 'library.circulation.view'],
        'principal' => ['admissions.documents.manage', 'admissions.documents.view', 'admissions.manage', 'admissions.view', 'attendance.manage', 'attendance.view', 'canteen.orders.manage', 'canteen.orders.view', 'curriculum.delivery.manage', 'curriculum.delivery.view', 'enrollments.manage', 'enrollments.view', 'examinations.papers.manage', 'examinations.papers.view', 'hostel.residency.manage', 'hostel.residency.view', 'hr.employees.manage', 'hr.employees.view', 'inventory.stock.manage', 'inventory.stock.view', 'library.circulation.manage', 'library.circulation.view', 'lms.assignments.manage', 'lms.assignments.view', 'lms.content.manage', 'lms.content.view', 'students.manage', 'students.view', 'timetable.schedule.manage', 'timetable.schedule.view', 'transport.assignments.manage', 'transport.assignments.view', 'transport.routes.view', 'visitor.visits.manage', 'visitor.visits.view'],
        'school_admin' => ['admissions.documents.manage', 'admissions.documents.view', 'admissions.manage', 'admissions.view', 'attendance.manage', 'attendance.view', 'canteen.orders.manage', 'canteen.orders.view', 'curriculum.delivery.manage', 'curriculum.delivery.view', 'enrollments.manage', 'enrollments.view', 'examinations.papers.manage', 'examinations.papers.view', 'finance.charges.view', 'finance.payments.record', 'finance.payments.view', 'hostel.residency.manage', 'hostel.residency.view', 'hr.employees.manage', 'hr.employees.view', 'inventory.stock.manage', 'inventory.stock.view', 'library.circulation.manage', 'library.circulation.view', 'lms.assignments.manage', 'lms.assignments.view', 'lms.content.manage', 'lms.content.view', 'students.manage', 'students.view', 'timetable.schedule.manage', 'timetable.schedule.view', 'transport.assignments.manage', 'transport.assignments.view', 'transport.routes.view', 'visitor.visits.manage', 'visitor.visits.view'],
        'stores_officer' => ['inventory.stock.manage', 'inventory.stock.view'],
        'transport_coordinator' => ['transport.assignments.manage', 'transport.assignments.view', 'transport.routes.view'],
    ];

    /** The templates a whole-School administrator may grant for one Campus. */
    private const CAMPUS_SCOPABLE = ['admissions_officer', 'canteen_operator', 'cashier', 'front_office', 'hostel_warden', 'hr_officer', 'librarian', 'principal', 'stores_officer', 'transport_coordinator'];

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
        // ORG.7E-1 (ADR 0076 §43): assignments define Campus placement itself, and
        // hr.employees.manage edits School-wide identity and onboards.
        'hr.employees.assignments.', 'hr.employees.manage',
        // ORG.7F-2 (ADR 0076 §46): children's identity and placement writes.
        'students.manage', 'enrollments.manage',
    ];

    /** Capability classes (CapabilityClasses) that make an adopted key high-risk wherever its prefix is. */
    private const HIGH_RISK_CLASSES = ['authority', 'financial'];

    /**
     * Owner-approved high-risk adoptions. ORG.7D-3 (ADR 0076 §39.3): the owner
     * authorized Canteen order adoption; fulfilment's charge is Canteen's
     * bounded side effect and confers no Fees, Payments or ledger authority.
     */
    private const OWNER_APPROVED_HIGH_RISK = [
        'canteen.orders.manage', 'canteen.orders.view',
        // ORG.7E-1 (ADR 0076 §43): owner-approved for the guarded operational
        // subset only -- identity edits need every current Campus in scope,
        // onboarding is atomic, assignment creation never captures an Employee,
        // and employment lifecycle, rehire, import, archive and account links
        // stay whole-School (declared operations).
        'hr.employees.assignments.manage', 'hr.employees.assignments.view', 'hr.employees.manage',
        // ORG.7F-2 (owner decision F2-D10): principal@Campus may edit the names of
        // an in-scope current Student and onboard a NEW Student atomically --
        // nothing else (lifecycle, transfer, existing-Student enroll, rollover,
        // Guardians and the full identity edit are declared whole-School).
        'enrollments.manage', 'students.manage',
        // ORG.7G-1 (ADR 0076 §50, owner decisions G1-D1..G1-D9): the Finance READ
        // keys -- stored Charge Campus, atomic single-Campus Payment visibility,
        // the debtor lookup; every Finance write, configuration, run, correction
        // and the ledger stay whole-School (and their holders non-scopable).
        'finance.charges.view', 'finance.payments.view',
        // ORG.7G-2 (ADR 0076 §51, owner decisions G2-D1..G2-D8): ordinary payment
        // ENTRY (a monetary write, HIGH-RISK): one non-NULL stored Campus in
        // scope, under Charge locks; corrections, refunds and reversals stay
        // whole-School.
        'finance.payments.record',
    ];

    /**
     * ORG.7G-1 (G1-D9..G1-D11): exactly what a Campus grant confers among
     * Finance keys -- cashier@Campus gains the two read keys (never
     * payments.record, which is not adopted); accountant and school_admin are
     * never Campus-scopable; principal holds no Finance key.
     */
    private const FINANCE_CAMPUS_CONFERRED = [
        'cashier' => ['finance.charges.view', 'finance.payments.record', 'finance.payments.view'],
    ];

    /**
     * ORG.7E-1 (owner decisions E1-1, E1-2, §40-§41): exactly what a Campus
     * grant of each HR-holding template confers among `hr.*` and `payroll.*`
     * keys. principal@Campus gains the operational Directory and core edit,
     * never personal/sensitive HR, documents, notes, qualifications, leave,
     * staff attendance, payroll or HR configuration; hr_officer@Campus gains
     * the operational subset only, with no bypass for its name.
     */
    private const HR_CAMPUS_CONFERRED = [
        'hr_officer' => ['hr.employees.assignments.manage', 'hr.employees.assignments.view', 'hr.employees.manage', 'hr.employees.view'],
        'principal' => ['hr.employees.manage', 'hr.employees.view'],
    ];

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
        // ORG.7F-1 adopted the two SIS READ keys only: every Student and
        // Enrollment write, rollover, processing authorizations and Guardian
        // authority stay out of Campus scope.
        // ORG.7F-2 adopted students.manage / enrollments.manage for the name
        // edit and atomic onboarding only.
        'students.processing_authorizations.', 'enrollments.rollovers.', 'guardians.',
    ];

    /**
     * ORG.7F-1 (ADR 0076 §45, owner decision F1-D7): exactly what a Campus
     * grant of each template confers among the SIS families -- principal@Campus
     * gains the Student Directory, operational overview and current-placement
     * reads, never a Student or Enrollment write, rollover, Guardian authority
     * or processing authorizations; no other Campus-scopable template gains
     * any SIS authority.
     */
    private const SIS_CAMPUS_CONFERRED = [
        'principal' => ['enrollments.manage', 'enrollments.view', 'students.manage', 'students.view'],
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
    public function a_campus_hr_grant_confers_only_the_operational_hr_subset(): void
    {
        $roles = Role::query()->where('scope', Role::SCOPE_SCHOOL)->where('is_system', true)->whereNull('retired_at')->with('capabilities')->orderBy('key')->get();
        $conferred = [];
        foreach ($roles as $role) {
            if (! StaffRoleCatalog::campusScopable($role)) {
                continue;
            }
            $keys = array_values(array_filter(
                CampusScopeCatalogue::adoptedAmong($role->capabilities->pluck('key')->sort()->values()->all()),
                fn (string $key) => str_starts_with($key, 'hr.') || str_starts_with($key, 'payroll.'),
            ));
            if ($keys !== []) {
                $conferred[$role->key] = $keys;
            }
        }
        $this->assertSame(self::HR_CAMPUS_CONFERRED, $conferred, 'A Campus grant changed what it confers among HR/payroll keys (ADR 0076 §43): report it, then update this snapshot.');
        $this->assertFalse(StaffRoleCatalog::campusScopable($roles->firstWhere('key', 'school_admin')), 'school_admin stays whole-School only (E1-3).');
        foreach (CampusScopeCatalogue::capabilities() as $capability) {
            foreach (['hr.employees.personal.', 'hr.employees.sensitive.', 'hr.employees.documents.', 'hr.employees.notes.', 'hr.employees.qualifications.', 'hr.leave.', 'hr.staff_attendance.', 'hr.departments.', 'hr.positions.', 'hr.categories.', 'payroll.'] as $prefix) {
                $this->assertStringStartsNotWith($prefix, $capability, "{$capability}: sensitive HR, leave, staff attendance, HR configuration and payroll are not Campus-adopted (ADR 0076 §43).");
            }
        }
    }

    #[Test]
    public function a_campus_grant_confers_only_the_sis_read_keys(): void
    {
        $roles = Role::query()->where('scope', Role::SCOPE_SCHOOL)->where('is_system', true)->whereNull('retired_at')->with('capabilities')->orderBy('key')->get();
        $conferred = [];
        foreach ($roles as $role) {
            if (! StaffRoleCatalog::campusScopable($role)) {
                continue;
            }
            $keys = array_values(array_filter(
                CampusScopeCatalogue::adoptedAmong($role->capabilities->pluck('key')->sort()->values()->all()),
                fn (string $key) => str_starts_with($key, 'students.') || str_starts_with($key, 'enrollments.') || str_starts_with($key, 'guardians.'),
            ));
            if ($keys !== []) {
                $conferred[$role->key] = $keys;
            }
        }
        $this->assertSame(self::SIS_CAMPUS_CONFERRED, $conferred, 'A Campus grant changed what it confers among SIS keys (ADR 0076 §45): report it, then update this snapshot.');
        $sis = array_values(array_filter(
            CampusScopeCatalogue::capabilities(),
            fn (string $key) => str_starts_with($key, 'students.') || str_starts_with($key, 'enrollments.') || str_starts_with($key, 'guardians.'),
        ));
        sort($sis);
        $this->assertSame(['enrollments.manage', 'enrollments.view', 'students.manage', 'students.view'], $sis, 'ORG.7F-1/7F-2 adopt exactly these SIS keys.');
        $this->assertFalse(StaffRoleCatalog::campusScopable($roles->firstWhere('key', 'school_admin')), 'school_admin stays whole-School only.');
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

    #[Test]
    public function a_campus_finance_grant_confers_only_the_read_keys_and_protected_finance_authority_is_never_scopable(): void
    {
        $roles = Role::query()->where('scope', Role::SCOPE_SCHOOL)->where('is_system', true)->whereNull('retired_at')->with('capabilities')->orderBy('key')->get();
        $conferred = [];
        foreach ($roles as $role) {
            $keys = $role->capabilities->pluck('key')->sort()->values()->all();
            $protected = array_values(array_filter($keys, fn (string $k) => CampusScopeCatalogue::isCampusIncompatible($k)));
            if ($protected !== []) {
                $this->assertFalse(StaffRoleCatalog::campusScopable($role), "{$role->key} holds whole-School Finance authority (".implode(', ', $protected).') and is never Campus-scopable (G1-D10).');
            }
            if (! StaffRoleCatalog::campusScopable($role)) {
                continue;
            }
            $finance = array_values(array_filter(CampusScopeCatalogue::adoptedAmong($keys), fn (string $k) => str_starts_with($k, 'finance.')));
            if ($finance !== []) {
                $conferred[$role->key] = $finance;
            }
        }
        $this->assertSame(self::FINANCE_CAMPUS_CONFERRED, $conferred, 'A Campus grant changed what it confers among Finance keys (ADR 0076 §50): report it, then update this snapshot.');

        $byKey = $roles->keyBy('key');
        $this->assertFalse(StaffRoleCatalog::campusScopable($byKey['accountant']), 'accountant stays whole-School (G1-D10).');
        $this->assertFalse(StaffRoleCatalog::campusScopable($byKey['school_admin']), 'school_admin stays whole-School.');
        $this->assertSame([], $byKey['principal']->capabilities->pluck('key')->filter(fn ($k) => str_starts_with($k, 'finance.'))->values()->all(), 'principal holds no Finance key (G1-D11).');
        foreach (['admissions_officer', 'canteen_operator', 'hostel_warden', 'librarian', 'transport_coordinator', 'front_office', 'stores_officer', 'hr_officer'] as $bounded) {
            $this->assertSame([], $byKey[$bounded]->capabilities->pluck('key')->filter(fn ($k) => str_starts_with($k, 'finance.'))->values()->all(), "{$bounded} keeps bounded side effects only, never Finance authority.");
        }
        $finance = array_values(array_filter(CampusScopeCatalogue::capabilities(), fn (string $k) => str_starts_with($k, 'finance.')));
        sort($finance);
        $this->assertSame(['finance.charges.view', 'finance.payments.record', 'finance.payments.view'], $finance, 'ORG.7G-1 reads + ORG.7G-2 payment entry, nothing else.');
        foreach ($finance as $key) {
            $this->assertFalse(CampusScopeCatalogue::isCampusIncompatible($key), "{$key} is never protected authority.");
        }
    }
}
