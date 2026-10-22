<?php

namespace Tests\Feature\Navigation;

use App\Models\School;
use App\Models\User;
use App\Support\Navigation\StaffNavigation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SWC.5 (ADR 0073 section 20.3, finding F-1): the Finance, HR and Payroll
 * hubs hold no data -- they group links to sub-pages that each check their
 * own capability. Before SWC.5 they rendered for ANY active member. Now a
 * hub opens exactly when its navigation entry is offered: the actor holds
 * at least one capability the hub renders a link for. One requirement
 * (StaffNavigation::hubRequirement()) drives both, so the menu and the page
 * cannot disagree; no capability is added and no sub-page changes.
 */
class HubAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    /**
     * The hub URL and, per requirement capability, the hub `can` flag that
     * capability turns on -- proving every requirement member yields at
     * least one link (none is a dead entry into an empty hub).
     */
    private const HUBS = [
        'finance' => ['/app/finance', [
            'finance.ledger.view' => 'viewLedger',
            'finance.charges.view' => 'viewCharges',
            'finance.payments.view' => 'viewPayments',
            'finance.payments.record' => 'recordPayments',
            'finance.fee_structures.view' => 'viewFeeSetup',
            'finance.fee_concessions.view' => 'viewConcessions',
            'finance.payment_corrections.view' => 'viewPaymentCorrections',
        ]],
        'hr' => ['/app/hr', [
            'hr.employees.view' => 'viewEmployees',
            'hr.employees.manage' => 'manageEmployees',
            'hr.departments.view' => 'viewDepartments',
            'hr.positions.view' => 'viewPositions',
            'hr.categories.view' => 'viewCategories',
        ]],
        'payroll' => ['/app/payroll', [
            'payroll.structures.view' => 'viewStructures',
            'payroll.compensation.view' => 'viewCompensation',
            'payroll.periods.manage' => 'managePeriods',
            'payroll.accounting.manage' => 'manageAccounting',
        ]],
        // V11.5 (ADR 0075 section 10): each capability opens at least one check.
        'data-health' => ['/app/data-health', [
            'enrollments.view' => 'viewPlacementChecks',
            'academics.years.view' => 'viewYearChecks',
            'academics.structure.view' => 'viewSectionChecks',
            'timetable.schedule.view' => 'viewTimetableChecks',
            'teaching.assignments.view' => 'viewTeachingChecks',
        ]],
    ];

    /**
     * Requirement members that render no hub link of their own, with the
     * reason. `payroll.runs.view` keeps its Phase 9 contract (any payroll
     * view capability offers Payroll); run pages are reached from Periods,
     * which needs `payroll.periods.manage`, and no seeded role holds
     * runs.view without it (ADR 0073 section 20.3).
     */
    private const LINKLESS = ['payroll' => ['payroll.runs.view']];

    /**
     * Hub links whose capability is never a requirement: statutory payroll
     * (E45) has no primary navigation entry (StaffNavigationCatalogueTest),
     * so its sole holder -- none in the seeded catalogue -- is refused the hub.
     */
    private const NEVER_REQUIRED = ['payroll' => ['payroll.statutory.view']];

    private function memberWith(array $capabilities, School $school): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    #[Test]
    public function the_hub_requirement_is_exactly_the_set_of_capabilities_it_links_for(): void
    {
        foreach (self::HUBS as $key => [, $flags]) {
            $this->assertEqualsCanonicalizing(
                [...array_keys($flags), ...(self::LINKLESS[$key] ?? [])],
                StaffNavigation::hubRequirement($key),
                "{$key}: requirement must equal the hub's link capabilities (plus the documented link-less ones)",
            );
        }
    }

    #[Test]
    public function a_member_with_none_of_the_hub_capabilities_is_refused_and_not_offered_it(): void
    {
        $school = $this->createSchool();
        // An active member with an unrelated capability -- before SWC.5 every hub rendered for them.
        $member = $this->memberWith(['students.view'], $school);

        foreach (self::HUBS as [$url]) {
            $this->actingAs($member)->get($url)->assertForbidden();
        }
        $this->actingAs($member)->get('/app')->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(shown: ['students'], hidden: array_keys(self::HUBS))));
    }

    #[Test]
    public function each_requirement_capability_alone_opens_the_hub_with_a_link_and_the_entry(): void
    {
        $school = $this->createSchool();

        foreach (self::HUBS as $key => [$url, $flags]) {
            foreach ($flags as $capability => $flag) {
                $user = $this->memberWith([$capability], $school);

                $this->actingAs($user)->get($url)->assertOk()->assertInertia(fn ($page) => $page
                    ->where("can.{$flag}", true));
                $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page
                    ->where('navigation', self::nav(shown: [$key])));
            }
        }
    }

    #[Test]
    public function documented_exceptions_hold(): void
    {
        $school = $this->createSchool();

        // payroll.runs.view alone: offered and opened (Phase 9 contract), with no link of its own.
        $runs = $this->memberWith(['payroll.runs.view'], $school);
        $this->actingAs($runs)->get('/app/payroll')->assertOk()->assertInertia(fn ($page) => $page
            ->where('can.viewRuns', true)->where('can.managePeriods', false));

        // payroll.statutory.view alone (E45, no seeded holder): no entry, no hub.
        $statutory = $this->memberWith(['payroll.statutory.view'], $school);
        $this->assertNotContains('payroll.statutory.view', StaffNavigation::hubRequirement('payroll'));
        $this->actingAs($statutory)->get('/app/payroll')->assertForbidden();
        $this->actingAs($statutory)->get('/app')->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(hidden: ['payroll'])));
    }

    #[Test]
    public function the_requirement_is_evaluated_in_the_active_school_only(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUserWithCapabilities($schoolA, ['finance.ledger.view']);
        $this->createMembership($user, $schoolB);

        $this->actingAs($user)->post("/app/schools/{$schoolA->id}/activate");
        $this->actingAs($user)->get('/app/finance')->assertOk();

        // School A's grant never opens School B's hub.
        $this->actingAs($user)->post("/app/schools/{$schoolB->id}/activate");
        $this->actingAs($user)->get('/app/finance')->assertForbidden();
    }

    #[Test]
    public function an_unknown_key_is_not_a_hub(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StaffNavigation::hubRequirement('students');
    }
}
