<?php

namespace Tests\Feature\Authorization;

use App\Domain\Identity\Application\Staff\StaffRolePresentation;
use App\Models\Role;
use App\Support\Authorization\CapabilityClasses;
use Database\Seeders\CapabilityAndRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SR.3 (ADR 0071 §4, §7, §12, §19): the production School staff catalogue as a
 * SECURITY CONTRACT. A release that adds, removes or changes any School system
 * role or any of its capabilities produces a review-visible diff here.
 *
 * Also: operational roles carry no authority, legal-gated or owned-scope
 * capability; the sensitive/financial ones carry exactly their contracted
 * keys; seeding is idempotent, goes through the admin connection only, never
 * grants anyone a role, and never defeats a retirement.
 */
class StaffRoleCatalogueSnapshotTest extends TestCase
{
    /** role key => exact sorted capability keys, for EVERY active School system role. */
    private const SNAPSHOT = [
        'accountant' => [
            'finance.accounts.manage', 'finance.charges.manage', 'finance.charges.view',
            'finance.fee_assessments.run', 'finance.fee_concessions.request', 'finance.fee_concessions.view',
            'finance.fee_structures.manage', 'finance.fee_structures.view', 'finance.ledger.post',
            'finance.ledger.view', 'finance.payments.record', 'finance.payments.view',
        ],
        'admissions_officer' => [
            'admissions.manage', 'admissions.view',
        ],
        'canteen_operator' => [
            'canteen.directory.manage', 'canteen.directory.view', 'canteen.orders.manage',
            'canteen.orders.view',
        ],
        'cashier' => [
            'finance.charges.view', 'finance.payments.record', 'finance.payments.view',
        ],
        'communications_coordinator' => [
            'communications.announce', 'communications.conversations.guardians', 'communications.reply',
            'communications.send', 'communications.templates.manage', 'communications.view',
        ],
        'front_office' => [
            'visitor.directory.manage', 'visitor.directory.view', 'visitor.visits.manage',
            'visitor.visits.view',
        ],
        'hostel_warden' => [
            'hostel.directory.manage', 'hostel.directory.view', 'hostel.residency.manage',
            'hostel.residency.view',
        ],
        'hr_officer' => [
            'hr.categories.manage', 'hr.categories.view', 'hr.departments.manage', 'hr.departments.view',
            'hr.employees.assignments.manage', 'hr.employees.assignments.view',
            'hr.employees.documents.manage', 'hr.employees.documents.view', 'hr.employees.manage',
            'hr.employees.notes.manage', 'hr.employees.notes.view', 'hr.employees.personal.manage',
            'hr.employees.personal.view', 'hr.employees.qualifications.manage',
            'hr.employees.qualifications.view', 'hr.employees.view', 'hr.leave.configure', 'hr.leave.manage',
            'hr.leave.view', 'hr.positions.manage', 'hr.positions.view', 'hr.staff_attendance.manage',
            'hr.staff_attendance.view',
        ],
        'hr_sensitive_records' => [
            'hr.employees.sensitive.manage', 'hr.employees.sensitive.view',
        ],
        'librarian' => [
            'library.catalogue.manage', 'library.catalogue.view', 'library.circulation.manage',
            'library.circulation.view', 'library.fines.view',
        ],
        'payroll_officer' => [
            'payroll.compensation.sensitive.manage',
            'payroll.compensation.sensitive.view', 'payroll.compensation.view', 'payroll.periods.manage',
            'payroll.runs.prepare', 'payroll.runs.view', 'payroll.structures.manage',
            'payroll.structures.view',
        ],
        'principal' => [
            'academics.structure.manage', 'academics.structure.view', 'academics.subjects.manage',
            'academics.subjects.view', 'academics.years.manage', 'academics.years.view', 'admissions.manage',
            'admissions.view', 'analytics.view', 'attendance.manage', 'attendance.view', 'automation.view',
            'canteen.directory.manage', 'canteen.directory.view', 'canteen.orders.manage',
            'canteen.orders.view', 'communications.announce', 'communications.approve',
            'communications.conversations.guardians', 'communications.reply', 'communications.send',
            'communications.templates.manage', 'communications.view', 'curriculum.delivery.manage',
            'curriculum.delivery.view', 'enrollments.manage', 'enrollments.rollovers.view',
            'enrollments.view', 'examinations.definitions.manage', 'examinations.definitions.view',
            'examinations.grade_scales.manage', 'examinations.grade_scales.view',
            'examinations.marks.correction.approve', 'examinations.marks.correction.request',
            'examinations.marks.lock', 'examinations.marks.manage', 'examinations.marks.view',
            'examinations.papers.manage', 'examinations.papers.view', 'guardians.manage', 'guardians.view',
            'hostel.directory.manage', 'hostel.directory.view', 'hostel.residency.manage',
            'hostel.residency.view', 'hr.employees.manage', 'hr.employees.personal.view',
            'hr.employees.view', 'hr.leave.approve', 'hr.leave.configure', 'hr.leave.manage',
            'hr.leave.view', 'hr.staff_attendance.manage', 'hr.staff_attendance.view',
            'inventory.directory.manage', 'inventory.directory.view', 'inventory.stock.manage',
            'inventory.stock.view', 'library.catalogue.manage', 'library.catalogue.view',
            'library.circulation.manage', 'library.circulation.view', 'lms.assignments.manage',
            'lms.assignments.view', 'lms.content.manage', 'lms.content.view', 'school.audit.view',
            'school.campuses.view', 'school.members.view', 'school.profile.view', 'school.settings.view',
            'students.manage', 'students.processing_authorizations.manage',
            'students.processing_authorizations.view', 'students.view', 'syllabus.manage', 'syllabus.view',
            'teaching.assignments.manage', 'teaching.assignments.view', 'timetable.periods.manage',
            'timetable.periods.view', 'timetable.schedule.manage', 'timetable.schedule.view',
            'transport.assignments.manage', 'transport.assignments.view', 'transport.routes.manage',
            'transport.routes.view', 'transport.vehicles.manage', 'transport.vehicles.view',
            'visitor.directory.manage', 'visitor.directory.view', 'visitor.visits.manage',
            'visitor.visits.view',
        ],
        'school_admin' => [
            'academics.structure.manage', 'academics.structure.view', 'academics.subjects.manage',
            'academics.subjects.view', 'academics.years.manage', 'academics.years.view', 'admissions.manage',
            'admissions.view', 'analytics.view', 'attendance.manage', 'attendance.teacher',
            'attendance.view', 'automation.manage', 'automation.view', 'canteen.directory.manage',
            'canteen.directory.view', 'canteen.orders.manage', 'canteen.orders.view',
            'canteen.settings.manage', 'canteen.settings.view', 'communications.announce',
            'communications.approve', 'communications.audit.view', 'communications.conversations.guardians',
            'communications.emergency', 'communications.manage', 'communications.reply',
            'communications.send', 'communications.templates.manage', 'communications.view',
            'curriculum.delivery.manage', 'curriculum.delivery.teacher', 'curriculum.delivery.view',
            'enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.rollovers.view',
            'enrollments.view', 'examinations.definitions.manage', 'examinations.definitions.view',
            'examinations.grade_scales.manage', 'examinations.grade_scales.view',
            'examinations.marks.correction.approve', 'examinations.marks.correction.request',
            'examinations.marks.lock', 'examinations.marks.manage', 'examinations.marks.teacher',
            'examinations.marks.view', 'examinations.papers.manage', 'examinations.papers.view',
            'finance.accounts.manage', 'finance.charges.manage', 'finance.charges.view',
            'finance.fee_assessments.run', 'finance.fee_concessions.approve',
            'finance.fee_concessions.request', 'finance.fee_concessions.view',
            'finance.fee_structures.manage', 'finance.fee_structures.view', 'finance.ledger.post',
            'finance.ledger.reverse', 'finance.ledger.view', 'finance.payments.record',
            'finance.payments.view', 'finance.periods.manage', 'guardians.manage', 'guardians.view',
            'hostel.directory.manage', 'hostel.directory.view', 'hostel.residency.manage',
            'hostel.residency.view', 'hr.employees.manage', 'hr.employees.personal.view',
            'hr.employees.view', 'hr.leave.approve', 'hr.leave.configure', 'hr.leave.manage',
            'hr.leave.self', 'hr.leave.view', 'hr.staff_attendance.manage', 'hr.staff_attendance.self',
            'hr.staff_attendance.view', 'integrations.api_clients.manage', 'integrations.api_clients.view',
            'integrations.webhooks.manage', 'integrations.webhooks.view', 'inventory.directory.manage',
            'inventory.directory.view', 'inventory.stock.manage', 'inventory.stock.view',
            'library.catalogue.manage', 'library.catalogue.view', 'library.circulation.manage',
            'library.circulation.view', 'library.fines.manage', 'library.fines.view', 'library.fines.void',
            'lms.assignments.manage', 'lms.assignments.teacher', 'lms.assignments.view',
            'lms.content.manage', 'lms.content.teacher', 'lms.content.view', 'payroll.accounting.manage',
            'payroll.compensation.view', 'payroll.payslips.self', 'payroll.periods.manage',
            'payroll.runs.approve', 'payroll.runs.post', 'payroll.runs.prepare', 'payroll.runs.reverse',
            'payroll.runs.view', 'payroll.structures.manage', 'payroll.structures.view', 'school.audit.view',
            'school.campuses.manage', 'school.campuses.view', 'school.domains.manage', 'school.domains.view',
            'school.members.manage', 'school.members.view', 'school.profile.manage', 'school.profile.view',
            'school.roles.grant.hr', 'school.roles.grant.hr_sensitive',
            'school.roles.grant.payroll_sensitive', 'school.roles.manage', 'school.roles.view',
            'school.settings.manage', 'school.settings.view', 'students.manage',
            'students.processing_authorizations.manage', 'students.processing_authorizations.view',
            'students.view', 'syllabus.manage', 'syllabus.view', 'teaching.assignments.manage',
            'teaching.assignments.view', 'timetable.periods.manage', 'timetable.periods.view',
            'timetable.schedule.manage', 'timetable.schedule.view', 'transport.assignments.manage',
            'transport.assignments.view', 'transport.routes.manage', 'transport.routes.view',
            'transport.vehicles.manage', 'transport.vehicles.view', 'visitor.directory.manage',
            'visitor.directory.view', 'visitor.visits.manage', 'visitor.visits.view',
        ],
        'staff_self_service' => [
            'hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self',
        ],
        'stores_officer' => [
            'inventory.directory.manage', 'inventory.directory.view', 'inventory.stock.manage',
            'inventory.stock.view',
        ],
        'teacher' => [
            'attendance.teacher', 'curriculum.delivery.teacher', 'examinations.marks.teacher',
            'lms.assignments.teacher', 'lms.content.teacher',
        ],
        'transport_coordinator' => [
            'transport.assignments.manage', 'transport.assignments.view', 'transport.routes.manage',
            'transport.routes.view', 'transport.vehicles.manage', 'transport.vehicles.view',
        ],
    ];

    /** ADR 0071 §4: the thirteen operational roles (SR.3). */
    private const OPERATIONAL = [
        'hr_officer', 'hr_sensitive_records', 'payroll_officer', 'accountant', 'cashier', 'librarian',
        'transport_coordinator', 'hostel_warden', 'front_office', 'stores_officer', 'canteen_operator',
        'admissions_officer', 'communications_coordinator',
    ];

    /**
     * The LIVE catalogue (role => sorted capabilities), so the class and
     * placement guards below protect independently of the snapshot constant.
     *
     * @return array<string, list<string>>
     */
    private function live(): array
    {
        return Role::query()->where('scope', 'school')->where('is_system', true)->with('capabilities')->get()
            ->mapWithKeys(fn (Role $role) => [$role->key => $role->capabilities->pluck('key')->sort()->values()->all()])
            ->all();
    }

    #[Test]
    public function the_school_system_catalogue_is_exactly_the_contracted_snapshot(): void
    {
        $roles = Role::query()->where('scope', 'school')->where('is_system', true)->with('capabilities')->orderBy('key')->get();

        $actual = [];
        foreach ($roles as $role) {
            $this->assertNull($role->retired_at, "{$role->key} is not retired.");
            $this->assertFalse($role->runtime_assignable, "{$role->key} is not a runtime-assignable platform role.");
            $actual[$role->key] = $role->capabilities->pluck('key')->sort()->values()->all();
        }

        $expected = self::SNAPSHOT;
        ksort($expected);
        $this->assertSame($expected, $actual, 'The School staff catalogue changed: review the role/capability diff against ADR 0071 §4.');
        $this->assertCount(17, $actual, 'Four original roles + the thirteen ADR 0071 §4 roles.');

        foreach (self::OPERATIONAL as $key) {
            $role = Role::query()->where('key', $key)->sole();
            $this->assertSame(['school', true], [$role->scope, $role->is_system], $key);
        }

        // Guardian is its own scope, never part of the staff catalogue.
        $this->assertSame('guardian', Role::query()->where('key', 'guardian')->value('scope'));
        // Explicitly deferred (ADR 0071 §5): no such production role exists.
        foreach (['academic_coordinator', 'exams_officer', 'statutory_payroll_officer', 'hr_payroll_officer', 'canteen_stores'] as $absent) {
            $this->assertFalse(Role::query()->where('key', $absent)->exists(), $absent);
        }
    }

    #[Test]
    public function operational_roles_carry_no_authority_legal_gated_or_owned_scope_capability(): void
    {
        $live = $this->live();
        foreach (self::OPERATIONAL as $key) {
            foreach ($live[$key] as $capability) {
                $classes = CapabilityClasses::of($capability);
                $this->assertNotEmpty($classes, "{$key}: {$capability} is classified.");
                foreach (['authority', 'legal-gated', 'owned-scope'] as $forbidden) {
                    $this->assertNotContains($forbidden, $classes, "{$key} must never carry {$capability} ({$forbidden}).");
                }
                $this->assertDoesNotMatchRegularExpression('/^portal\.|\.teacher$|\.self$|^examinations\.marks\.|^payroll\.statutory\.|^school\.|^analytics\.export$|^communications\.conversations\.students$/', $capability, "{$key}: {$capability}");
                $this->assertNotContains($capability, CapabilityClasses::GRANT_RIGHT_KEYS, "{$key} never carries a grant right.");
            }
        }
    }

    #[Test]
    public function the_sensitive_and_checker_keys_sit_only_where_the_contract_puts_them(): void
    {
        $live = $this->live();
        $holders = function (string $capability) use ($live): array {
            $keys = [];
            foreach ($live as $role => $capabilities) {
                if (in_array($capability, $capabilities, true)) {
                    $keys[] = $role;
                }
            }
            sort($keys);

            return $keys;
        };

        // Highly sensitive / grant-right-covered: exactly the contracted operational roles.
        $this->assertSame(['hr_sensitive_records'], $holders('hr.employees.sensitive.view'));
        $this->assertSame(['hr_sensitive_records'], $holders('hr.employees.sensitive.manage'));
        $this->assertSame(['payroll_officer'], $holders('payroll.compensation.sensitive.view'));
        $this->assertSame(['payroll_officer'], $holders('payroll.compensation.sensitive.manage'));
        foreach (CapabilityClasses::GRANT_RIGHTS as $covered => $right) {
            $this->assertNotContains('school_admin', $holders($covered), "school_admin never holds the covered {$covered} (D3).");
        }

        // Checkers and closures stay with administrators (ADR 0071 §9).
        foreach (['payroll.runs.approve', 'payroll.runs.post', 'payroll.runs.reverse', 'finance.ledger.reverse', 'finance.periods.manage', 'finance.fee_concessions.approve', 'library.fines.manage', 'library.fines.void', 'canteen.settings.manage', 'communications.approve', 'communications.emergency', 'communications.manage'] as $checker) {
            $this->assertSame([], array_values(array_intersect($holders($checker), self::OPERATIONAL)), "{$checker} is on no operational role.");
        }
        foreach (['payroll.statutory.view', 'payroll.statutory.manage', 'payroll.statutory.identifiers.view', 'payroll.statutory.identifiers.manage', 'payroll.statutory.exports.generate', 'communications.conversations.students', 'analytics.export'] as $gated) {
            $this->assertSame([], $holders($gated), "{$gated} is legally gated: on no School role.");
        }

        // hr_sensitive_records is an add-on (exactly two keys); hr_officer has neither.
        $this->assertSame(['hr.employees.sensitive.manage', 'hr.employees.sensitive.view'], $live['hr_sensitive_records']);
        $this->assertSame([], array_values(array_filter($live['hr_officer'], fn ($k) => str_starts_with($k, 'hr.employees.sensitive.'))));
        // Cashier: payments + charge view only, no Student capability.
        $this->assertSame(['finance.charges.view', 'finance.payments.record', 'finance.payments.view'], $live['cashier']);
        // Canteen and stores are separate roles (no inventory on canteen, no canteen on stores).
        $this->assertSame([], array_values(array_filter($live['canteen_operator'], fn ($k) => ! str_starts_with($k, 'canteen.'))));
        $this->assertSame([], array_values(array_filter($live['stores_officer'], fn ($k) => ! str_starts_with($k, 'inventory.'))));
    }

    #[Test]
    public function every_operational_role_is_grantable_by_school_admin_through_held_keys_or_its_grant_right(): void
    {
        $live = $this->live();
        $admin = $live['school_admin'];
        $rights = [];
        foreach (self::OPERATIONAL as $key) {
            $used = [];
            foreach ($live[$key] as $capability) {
                if (in_array($capability, $admin, true)) {
                    continue;
                }
                $right = DB::table('capabilities')->where('key', $capability)->value('grant_right');
                $this->assertNotNull($right, "{$key}: school_admin neither holds nor can cover {$capability}.");
                $this->assertContains($right, $admin);
                $used[$right] = true;
            }
            $rights[$key] = array_keys($used);
        }

        $this->assertSame(['school.roles.grant.hr'], $rights['hr_officer']);
        $this->assertSame(['school.roles.grant.hr_sensitive'], $rights['hr_sensitive_records']);
        $this->assertSame(['school.roles.grant.payroll_sensitive'], $rights['payroll_officer']);
        foreach (array_diff(self::OPERATIONAL, ['hr_officer', 'hr_sensitive_records', 'payroll_officer']) as $key) {
            $this->assertSame([], $rights[$key], "{$key} needs no grant right.");
        }
    }

    #[Test]
    public function the_presentation_map_covers_exactly_the_catalogue_and_is_display_only(): void
    {
        $presented = array_keys(StaffRolePresentation::ROLES);
        $catalogue = array_keys(self::SNAPSHOT);
        sort($presented);
        sort($catalogue);
        $this->assertSame($catalogue, $presented, 'Every catalogue role has a presentation entry, and no stale one remains.');

        // Display only: nothing but the catalogue listing reads it (never an authorization input).
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());
            if (str_contains($file->getContents(), 'StaffRolePresentation') && $path !== 'app/Domain/Identity/Application/Staff/StaffRolePresentation.php') {
                $this->assertSame('app/Domain/Identity/Application/Staff/StaffRoleCatalog.php', $path);
            }
        }
    }

    #[Test]
    public function seeding_is_idempotent_admin_only_grants_nobody_a_role_and_never_unretires(): void
    {
        $admin = DB::connection(CapabilityAndRoleSeeder::CATALOGUE_CONNECTION);
        $counts = fn (): array => [
            'roles' => $admin->table('roles')->count(),
            'role_capabilities' => $admin->table('role_capabilities')->count(),
            'grant_rights' => $admin->table('capabilities')->whereNotNull('grant_right')->orderBy('key')->pluck('grant_right', 'key')->all(),
            'grants' => $admin->table('membership_role_assignments')->count(),
            'links' => $admin->table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')->where('r.is_system', true)
                ->orderBy('r.key')->orderBy('rc.capability_key')->get(['r.key', 'rc.capability_key'])->map(fn ($row) => $row->key.':'.$row->capability_key)->all(),
        ];

        // Inside one admin-connection transaction (rolled back): the seeder writes
        // only through that connection, so it runs inside it.
        $admin->beginTransaction();
        try {
            $before = $counts();
            (new CapabilityAndRoleSeeder)->run();
            $once = $counts();
            (new CapabilityAndRoleSeeder)->run();
            $this->assertSame($before, $once, 'A re-run on a seeded database changes nothing.');
            $this->assertSame($once, $counts(), 'A second re-run changes nothing either.');

            // Retirement is never defeated by a re-run (the seeder never writes retired_at).
            $admin->table('roles')->where('key', 'cashier')->update(['retired_at' => now()]);
            (new CapabilityAndRoleSeeder)->run();
            $this->assertNotNull($admin->table('roles')->where('key', 'cashier')->value('retired_at'), 'A retired production role stays retired.');
            $this->assertSame(self::SNAPSHOT['cashier'], $admin->table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')
                ->where('r.key', 'cashier')->orderBy('rc.capability_key')->pluck('rc.capability_key')->all(), 'Its definition is kept (history stays identifiable).');
        } finally {
            $admin->rollBack();
        }

        $this->assertNull(Role::query()->where('key', 'cashier')->value('retired_at'));

        // The runtime role can never seed the catalogue (SR.1 privileges hold).
        try {
            DB::transaction(fn () => DB::connection()->table('roles')->insert(['id' => (string) Str::uuid7(), 'key' => 'runtime.attempt', 'name' => 'x', 'scope' => 'school', 'is_system' => true]));
            $this->fail('The runtime role wrote the catalogue.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }
}
