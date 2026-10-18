<?php

namespace Tests\Feature\Authorization\Campus;

use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Models\Role;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
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
    /** System School role template => the adopted keys it holds (ORG.7A/7B baseline). */
    private const HOLDERS = [
        'front_office' => ['visitor.visits.manage', 'visitor.visits.view'],
        'principal' => ['inventory.stock.manage', 'inventory.stock.view', 'visitor.visits.manage', 'visitor.visits.view'],
        'school_admin' => ['inventory.stock.manage', 'inventory.stock.view', 'visitor.visits.manage', 'visitor.visits.view'],
        'stores_officer' => ['inventory.stock.manage', 'inventory.stock.view'],
    ];

    /** The templates a whole-School administrator may grant for one Campus. */
    private const CAMPUS_SCOPABLE = ['front_office', 'principal', 'stores_officer'];

    /** Families that need an explicit owner approval (recorded here, by key) before adoption. */
    private const HIGH_RISK = [
        'hr.employees.personal.', 'hr.employees.sensitive.', 'hr.employees.documents.', 'hr.employees.notes.',
        'hr.employees.qualifications.', 'payroll.', 'finance.', 'school.', 'integrations.', 'capabilities.',
    ];

    /** Owner-approved high-risk adoptions (none yet). */
    private const OWNER_APPROVED_HIGH_RISK = [];

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
            $highRisk = str_ends_with($capability, '.approve');
            foreach (self::HIGH_RISK as $prefix) {
                $highRisk = $highRisk || str_starts_with($capability, $prefix);
            }
            if ($highRisk) {
                $this->assertContains($capability, self::OWNER_APPROVED_HIGH_RISK, "{$capability} is a high-risk family: adoption needs an explicit owner approval recorded here.");
            }
        }
    }
}
