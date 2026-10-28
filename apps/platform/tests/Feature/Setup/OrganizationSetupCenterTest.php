<?php

namespace Tests\Feature\Setup;

use App\Support\Setup\OrganizationSetupCenter;
use App\Support\Setup\OrganizationSetupRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.12B (ADR 0080 §7): the Setup Center's derived content -- progress,
 * next actions, server-side actions and prerequisite naming -- from
 * readiness items. Synthetic items over the real catalogue; no database.
 */
class OrganizationSetupCenterTest extends TestCase
{
    /** Every capability any definition opens or manages: a viewer who can do everything. */
    private function everything(): array
    {
        $all = [];
        foreach (app(OrganizationSetupRegistry::class)->all() as $definition) {
            $all = [...$all, ...$definition->openCapabilities, ...$definition->manageCapabilities];
        }

        return array_values(array_unique($all));
    }

    /**
     * @param  array<string, string>  $statuses  key => status; default configured
     * @param  array<string, list<string>>  $blockers
     * @return list<array<string, mixed>>
     */
    private function items(array $statuses = [], array $blockers = [], array $hidden = []): array
    {
        return array_map(fn ($definition): array => [
            ...$definition->toArray(),
            'status' => $statuses[$definition->key] ?? 'configured',
            'explanation' => 'x',
            'completedChecks' => ($statuses[$definition->key] ?? 'configured') === 'configured' ? 1 : 0,
            'totalChecks' => 1,
            'blockers' => $blockers[$definition->key] ?? [],
            'hiddenBlockers' => $hidden[$definition->key] ?? 0,
        ], app(OrganizationSetupRegistry::class)->all());
    }

    private function present(array $items, ?array $held = null): array
    {
        return OrganizationSetupCenter::present($items, $held ?? $this->everything(), app(OrganizationSetupRegistry::class));
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(array $presented): array
    {
        $cards = [];
        foreach ($presented['categories'] as $category) {
            foreach ($category['items'] as $card) {
                $cards[$card['key']] = $card;
            }
        }

        return $cards;
    }

    #[Test]
    public function progress_separates_core_from_enabled_modules_and_excludes_not_applicable(): void
    {
        $presented = $this->present($this->items([
            'organization.school_profile' => 'partially_configured',
            'academic.academic_year' => 'attention_required',
            'academic.structure' => 'blocked',
            'transport.transport' => 'not_applicable',
            'library.library' => 'not_applicable',
            'finance.fees' => 'not_configured',
        ]));
        $o = $presented['overview'];

        // 8 core areas; 3 incomplete. 13 optional; 2 not enabled, 1 incomplete.
        $this->assertSame(['configured' => 5, 'total' => 8], $o['core']);
        $this->assertSame(['configured' => 10, 'total' => 11], $o['optional']);
        $this->assertSame([1, 1, 2, 0], [$o['attention'], $o['blocked'], $o['notEnabled'], $o['hiddenAreas']]);
        $this->assertSame('core_in_progress', $o['state']);

        $fresh = array_map(fn (array $item): array => match (true) {
            $item['key'] === 'compliance.audit' => [...$item, 'totalChecks' => null, 'completedChecks' => null], // needs no setup
            $item['core'] => [...$item, 'status' => 'not_configured', 'completedChecks' => 0],
            default => $item,
        }, $this->items());
        $this->assertSame('getting_started', $this->present($fresh)['overview']['state'], 'an area needing no setup does not end "getting started"');
        $this->assertEqualsCanonicalizing(['transport.transport', 'library.library'], array_column($presented['notEnabled'], 'key'));
        $this->assertArrayNotHasKey('transport.transport', $this->cards($presented), 'a module not enabled is listed apart, never as an incomplete area');
    }

    #[Test]
    public function an_optional_module_never_holds_back_core_completion_and_nothing_claims_production_readiness(): void
    {
        $core = $this->present($this->items(['finance.fees' => 'not_configured']))['overview'];
        $this->assertSame(['configured' => 8, 'total' => 8], $core['core']);
        $this->assertSame('core_complete', $core['state']);

        $allOff = array_fill_keys(array_map(fn ($d) => $d->key, array_filter(app(OrganizationSetupRegistry::class)->all(), fn ($d) => ! $d->isCore())), 'not_applicable');
        $done = $this->present($this->items($allOff))['overview'];
        $this->assertSame(['complete', ['configured' => 0, 'total' => 0]], [$done['state'], $done['optional']]);

        $this->assertDoesNotMatchRegularExpression('/production|ready|secure|compliance passed/i', json_encode($done).json_encode($core));
    }

    #[Test]
    public function next_actions_unblock_first_then_core_then_optional_and_stop_at_three(): void
    {
        $presented = $this->present($this->items([
            'organization.school_profile' => 'not_configured',
            'organization.campuses' => 'not_configured',
            'academic.academic_year' => 'not_configured',
            'academic.structure' => 'blocked',
            'staff.staff_records' => 'partially_configured',
            'finance.fees' => 'not_configured',
        ], ['academic.structure' => ['academic.academic_year', 'organization.campuses']]));

        $this->assertSame(
            ['organization.campuses', 'academic.academic_year', 'organization.school_profile'],
            array_column($presented['nextActions'], 'key'),
            'the two prerequisites of a blocked area first, by registry order, then core not configured',
        );
        $this->assertSame('Other setup areas are waiting for this.', $presented['nextActions'][0]['reason']);
        $this->assertSame('/app/school-setup/campuses', $presented['nextActions'][0]['href']);
        $this->assertCount(OrganizationSetupCenter::MAX_NEXT_ACTIONS, $presented['nextActions']);
        $this->assertNotContains('academic.structure', array_column($presented['nextActions'], 'key'), 'a blocked area is never recommended');
    }

    #[Test]
    public function the_priority_order_runs_core_statuses_before_optional_ones(): void
    {
        $presented = $this->present($this->items([
            'finance.fees' => 'not_configured',
            'staff.staff_records' => 'attention_required',
            'organization.school_profile' => 'partially_configured',
        ]));

        $this->assertSame(['organization.school_profile', 'staff.staff_records', 'finance.fees'], array_column($presented['nextActions'], 'key'));
        $this->assertSame(['Partly configured.', 'Needs attention.', 'Not configured yet.'], array_column($presented['nextActions'], 'reason'));
    }

    #[Test]
    public function actions_are_resolved_server_side_from_held_capabilities(): void
    {
        $items = $this->items(['organization.school_profile' => 'not_configured', 'academic.academic_year' => 'not_configured', 'transport.transport' => 'not_applicable']);
        $held = ['school.profile.view', 'academics.years.view', 'academics.years.manage', 'transport.routes.view', 'transport.routes.manage', 'transport.vehicles.manage'];
        $cards = $this->cards($presented = $this->present($items, $held));

        $this->assertSame(['kind' => 'view', 'href' => '/app/school-setup/profile'], $cards['organization.school_profile']['action'], 'open without manage = View');
        $this->assertSame(['kind' => 'configure', 'href' => '/app/school-setup/academic-years'], $cards['academic.academic_year']['action']);
        $this->assertSame(['kind' => 'none', 'href' => null], $cards['finance.fees']['action'], 'no destination capability = no link');
        $this->assertSame(['kind' => 'none', 'href' => null], $cards['compliance.audit']['action'], 'an area nobody configures here is never Configure');
        $this->assertSame(['academic.academic_year'], array_column($presented['nextActions'], 'key'), 'only an area the viewer can configure is recommended');
        $this->assertArrayNotHasKey('action', $presented['notEnabled'][0], 'a module not enabled offers no action');
    }

    #[Test]
    public function a_visible_prerequisite_is_named_and_a_hidden_one_is_only_flagged(): void
    {
        $cards = $this->cards($this->present($this->items(
            ['academic.structure' => 'blocked', 'attendance.student_attendance' => 'blocked'],
            ['academic.structure' => ['academic.academic_year']],
            ['attendance.student_attendance' => 1],
        )));

        $this->assertSame([['key' => 'academic.academic_year', 'title' => 'Academic year']], $cards['academic.structure']['prerequisites']);
        $this->assertFalse($cards['academic.structure']['hiddenPrerequisite']);
        $this->assertSame([], $cards['attendance.student_attendance']['prerequisites']);
        $this->assertTrue($cards['attendance.student_attendance']['hiddenPrerequisite']);
    }

    #[Test]
    public function the_payload_carries_no_capability_route_name_or_raw_dependency_list(): void
    {
        $json = (string) json_encode($this->present($this->items()));

        foreach (['school.profile.view', 'academics.years.manage', 'app.school-setup', '"dependsOn"', '"route"', 'Capabilities'] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
    }
}
