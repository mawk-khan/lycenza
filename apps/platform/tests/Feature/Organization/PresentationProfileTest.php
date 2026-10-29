<?php

namespace Tests\Feature\Organization;

use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkBrandChangeReason;
use App\Domain\Platform\Application\Groups\NetworkBrandService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Schools\Application\SchoolPresentationService;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\NavigationPresentationCatalogue as Catalogue;
use App\Support\Presentation\BrandColor;
use App\Support\Presentation\BrandResolver;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12F (ADR 0080 §11): presentation profiles.
 *
 * - Navigation: order within a code-owned group, hide (never a required
 *   item), plain label alias, reset; stale-safe, audited, no-op when unchanged.
 * - Presentation is never security: a hidden item's page still opens for an
 *   authorized person; an unauthorized person never sees it nor opens it; a
 *   disabled module's item stays absent; one School never affects another.
 * - Branding: School value > EXPLICITLY chosen Network brand (member, active
 *   Group) > system default. Relationship type never decides; leaving the
 *   Group clears the source; an archived Group falls back with attention.
 * - Network brand: Network Manager of THAT Group only, fresh MFA, reason,
 *   version, audit.
 */
class PresentationProfileTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private function presentation(): SchoolPresentationService
    {
        return app(SchoolPresentationService::class);
    }

    /** @return list<string> the rendered navigation keys of $group for $user in $school, in order */
    private function navKeys(User $user, School $school, string $group): array
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $groups = $this->get('/app')->viewData('page')['props']['navigation']['groups'];

        return array_column(collect($groups)->firstWhere('key', $group)['items'] ?? [], 'key');
    }

    private function labelOf(User $user, School $school, string $key): ?string
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        foreach ($this->get('/app')->viewData('page')['props']['navigation']['groups'] as $group) {
            foreach ($group['items'] as $item) {
                if ($item['key'] === $key) {
                    return $item['label'];
                }
            }
        }

        return null;
    }

    private function save(User $actor, School $school, array $changes, string $audience = Catalogue::STAFF_WEB): array
    {
        $items = [];
        foreach (Catalogue::items($audience) as $key => $item) {
            $items[] = ['key' => $key, 'hidden' => false, 'order' => $item['order'], 'label' => null, ...($changes[$key] ?? [])];
        }

        return $this->presentation()->saveNavigation($actor, $school, $audience, $items, $this->presentation()->navigationFingerprint($school, $audience));
    }

    #[Test]
    public function a_school_reorders_hides_and_relabels_eligible_items_and_reset_restores_the_defaults(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $default = $this->navKeys($admin, $school, 'administration');
        $this->assertContains('data-health', $default);

        $changed = $this->save($admin, $school, [
            'data-health' => ['hidden' => true],
            'students' => ['label' => '  Learners  '],
            'staff-accounts' => ['order' => 0],
            'school-settings' => ['order' => 1],
        ]);
        $this->assertEqualsCanonicalizing(['data-health', 'students', 'staff-accounts', 'school-settings'], $changed);

        $now = $this->navKeys($admin, $school, 'administration');
        $this->assertNotContains('data-health', $now);
        $this->assertSame('staff-accounts', $now[0]);
        $this->assertSame('Learners', $this->labelOf($admin, $school, 'students'));

        // Only overrides are stored; an unchanged save is a no-op without an audit.
        $this->assertSame(4, $this->in($school, fn () => DB::table('school_navigation_preferences')->count()));
        $audits = $this->in($school, fn () => DB::table('school_audit_events')->where('event_type', 'school.presentation.navigation_updated')->count());
        $this->assertSame([], $this->save($admin, $school, ['data-health' => ['hidden' => true], 'students' => ['label' => 'Learners'], 'staff-accounts' => ['order' => 0], 'school-settings' => ['order' => 1]]));
        $this->assertSame($audits, $this->in($school, fn () => DB::table('school_audit_events')->where('event_type', 'school.presentation.navigation_updated')->count()));

        $this->presentation()->resetNavigation($admin, $school, Catalogue::STAFF_WEB, $this->presentation()->navigationFingerprint($school, Catalogue::STAFF_WEB));
        $this->assertSame($default, $this->navKeys($admin, $school, 'administration'));
        $this->assertSame(0, $this->in($school, fn () => DB::table('school_navigation_preferences')->count()));
    }

    #[Test]
    public function invalid_presentation_is_refused(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $fp = $this->presentation()->navigationFingerprint($school, Catalogue::STAFF_WEB);

        foreach ([
            [['key' => 'not-a-real-item']],
            [['key' => 'presentation', 'hidden' => true]],
            [['key' => 'students', 'label' => '<script>x</script>']],
            [['key' => 'students', 'label' => str_repeat('a', 41)]],
            [['key' => 'students', 'label' => "Stud\u{202E}stne"]], // a bidi override (review L1)
            [['key' => 'students', 'label' => "Stu\u{200B}dents"]], // an invisible character
            [['key' => 'students', 'order' => 5000]],
            [['key' => 'portal-fees']], // a Guardian portal item is not a staff item
        ] as $items) {
            $this->assertThrows(fn () => $this->presentation()->saveNavigation($admin, $school, Catalogue::STAFF_WEB, $items, $fp), ValidationException::class);
        }
        $this->assertThrows(fn () => $this->presentation()->saveNavigation($admin, $school, 'student_portal', [], $fp), ValidationException::class);
        // Stale: the profile changed since the form opened.
        $this->save($admin, $school, ['data-health' => ['hidden' => true]]);
        $this->assertThrows(fn () => $this->presentation()->saveNavigation($admin, $school, Catalogue::STAFF_WEB, [], $fp), ValidationException::class);
        // The database refuses markup too.
        $this->assertThrows(fn () => $this->in($school, fn () => DB::transaction(fn () => DB::table('school_navigation_preferences')->insert([
            'id' => (string) str()->uuid(), 'school_id' => $school->id, 'audience' => 'staff_web', 'navigation_key' => 'students', 'label_override' => '<b>x</b>',
        ]))), QueryException::class);
    }

    #[Test]
    public function hidden_is_not_denied_and_visible_is_not_granted(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $teacher = $this->createUserWithCapabilities($school, ['attendance.teacher']);
        $this->save($admin, $school, ['data-health' => ['hidden' => true], 'students' => ['label' => 'Learners', 'order' => 0]]);

        // Authorized and hidden: absent from the menu, the page still opens.
        $this->assertNotContains('data-health', $this->navKeys($admin, $school, 'administration'));
        $this->get('/app/data-health')->assertOk();

        // Unauthorized with a preference: absent, and the page is refused.
        $this->assertSame([], $this->navKeys($teacher, $school, 'people'));
        $this->assertNull($this->labelOf($teacher, $school, 'students'));
        $this->get('/app/students')->assertForbidden();
    }

    #[Test]
    public function a_disabled_module_item_stays_absent_whatever_the_preference(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->save($admin, $school, ['library-catalogue' => ['label' => 'Books', 'order' => 0]]);
        $this->assertSame('Books', $this->labelOf($admin, $school, 'library-catalogue'));

        SchoolFactory::setOptionalModules($school, false, ['library']);
        app(CapabilityResolver::class)->forgetCache($admin, $school);
        $this->assertNull($this->labelOf($admin, $school, 'library-catalogue'));
        $this->assertNotSame(200, $this->get('/app/library/titles')->status());
        // The preference survives and returns with the module (it never enables it).
        $this->assertSame(1, $this->in($school, fn () => DB::table('school_navigation_preferences')->where('navigation_key', 'library-catalogue')->count()));
    }

    #[Test]
    public function one_schools_presentation_never_affects_another(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $person = $this->schoolAdminFor($a);
        $this->assignSchoolRole($this->createMembership($person, $b), 'school_admin');
        $this->save($person, $a, ['students' => ['label' => 'Learners']]);

        $this->assertSame('Learners', $this->labelOf($person, $a, 'students'));
        $this->assertSame('Students', $this->labelOf($person, $b, 'students'));
    }

    #[Test]
    public function only_settings_managers_change_presentation_and_principals_only_view(): void
    {
        $school = $this->createSchool();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');
        $teacher = $this->createUserWithCapabilities($school, ['attendance.teacher']);

        $this->actingAs($principal)->post("/app/schools/{$school->id}/activate");
        $this->get('/app/school-setup/presentation')->assertOk()->assertInertia(fn ($p) => $p->where('canManage', false));
        $this->put('/app/school-setup/presentation/navigation/staff_web', ['fingerprint' => 'x', 'items' => []])->assertForbidden();
        $this->put('/app/school-setup/presentation/brand', ['fingerprint' => 'x'])->assertForbidden();
        $this->assertThrows(fn () => $this->save($principal, $school, []), AuthorizationException::class);

        $this->actingAs($teacher)->post("/app/schools/{$school->id}/activate");
        $this->get('/app/school-setup/presentation')->assertForbidden();

        // The page never shadows the shell's shared props (`navigation`, `brand`): a collision blanks the layout.
        $this->actingAs($principal)->post("/app/schools/{$school->id}/activate");
        $this->get('/app/school-setup/presentation')->assertInertia(fn ($p) => $p->has('navigation.groups')->has('navigation.home')->has('profiles.staff_web.items')->has('brandProfile.source'));
    }

    #[Test]
    public function the_guardian_portal_audience_keeps_its_required_item(): void
    {
        $items = Catalogue::items(Catalogue::GUARDIAN_PORTAL);
        $this->assertSame(['portal-communications', 'portal-conversations', 'portal-calendar', 'portal-attendance', 'portal-fees'], array_keys($items));
        $this->assertFalse($items['portal-communications']['hideable']);

        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->save($admin, $school, ['portal-fees' => ['hidden' => true], 'portal-calendar' => ['order' => 0]], Catalogue::GUARDIAN_PORTAL);
        $this->assertThrows(fn () => $this->save($admin, $school, ['portal-communications' => ['hidden' => true]], Catalogue::GUARDIAN_PORTAL), ValidationException::class);
        $this->assertSame(2, $this->in($school, fn () => DB::table('school_navigation_preferences')->where('audience', 'guardian_portal')->count()));
    }

    // ------------------------------------------------------------------
    // Branding
    // ------------------------------------------------------------------

    private function networkWith(School ...$schools): array
    {
        $root = $this->createPlatformRoot();
        $group = $this->createGroup([]);
        foreach ($schools as $school) {
            app(SchoolGroupGovernanceService::class)->addSchool($root, $group, $school->id, SchoolGroupRelationship::FRANCHISE, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        }
        $manager = $this->createUser();
        app(NetworkAccessService::class)->grant($root, $group, $manager->email, NetworkAccessService::MANAGER, null, null, NetworkRoleChangeReason::APPOINTMENT, $this->freshProof($root));
        app(NetworkBrandService::class)->update($manager, $group, 'Northstar', '#1d4e89', 0, NetworkBrandChangeReason::BRAND_LAUNCH, $this->freshProof($manager));

        return [$group, $manager, $root];
    }

    private function brand(School $school): array
    {
        return app(BrandResolver::class)->forSchool($school);
    }

    private function saveBrand(User $actor, School $school, ?string $source, ?string $label = null, ?string $accent = null): array
    {
        return $this->presentation()->saveBrand($actor, $school, $source, $label, $accent, $this->presentation()->brandFingerprint($school));
    }

    #[Test]
    public function network_branding_applies_only_after_an_explicit_choice_and_school_values_take_precedence(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        [$group] = $this->networkWith($school);

        $this->assertSame('system', $this->brand($school)['source'], 'franchise membership alone applies nothing');

        $this->saveBrand($admin, $school, $group->id);
        $this->assertSame(['network', 'Northstar', '#1D4E89'], [$this->brand($school)['source'], $this->brand($school)['label'], $this->brand($school)['accent']]);

        $this->saveBrand($admin, $school, $group->id, null, '#F5D90A');
        $mixed = $this->brand($school);
        $this->assertSame(['mixed', 'Northstar', '#F5D90A', BrandColor::DARK], [$mixed['source'], $mixed['label'], $mixed['accent'], $mixed['foreground']], 'a light accent gets dark text');
        $this->assertNotSame($school->refresh()->name, 'Northstar', 'the School name never changes');

        // Shared with the shell, for this School only.
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");
        $this->get('/app')->assertInertia(fn ($p) => $p->where('brand.label', 'Northstar')->where('brand.source', 'mixed'));
    }

    #[Test]
    public function a_school_can_only_choose_a_group_it_belongs_to_and_only_its_choice_applies(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        [$groupA] = $this->networkWith($school);
        [$groupB] = $this->networkWith($school);
        [$foreign] = $this->networkWith($this->createSchool());

        $this->assertThrows(fn () => $this->saveBrand($admin, $school, $foreign->id), ValidationException::class);
        $this->assertThrows(fn () => $this->in($school, fn () => DB::transaction(fn () => DB::table('school_brand_profiles')->insert([
            'id' => (string) str()->uuid(), 'school_id' => $school->id, 'brand_source_group_id' => $foreign->id,
        ]))), QueryException::class, null);

        app(NetworkBrandService::class); // both groups brand "Northstar" by default -- make B distinct
        DB::table('school_group_brand_profiles')->where('school_group_id', $groupB->id)->update(['display_label' => 'Other Network']);
        $this->saveBrand($admin, $school, $groupA->id);
        $this->assertSame('Northstar', $this->brand($school)['label']);
        $this->assertSame($groupA->id, $this->brand($school)['network']['id']);
    }

    #[Test]
    public function leaving_or_archiving_the_source_group_falls_back_safely(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        [$group, , $root] = $this->networkWith($school);
        $this->saveBrand($admin, $school, $group->id);

        app(SchoolGroupGovernanceService::class)->archive($root, $group->refresh());
        $archived = $this->brand($school);
        $this->assertSame('system', $archived['source']);
        $this->assertNotNull($archived['attention']);

        $other = $this->createSchool();
        $otherAdmin = $this->schoolAdminFor($other);
        [$group2, , $root2] = $this->networkWith($other);
        $this->saveBrand($otherAdmin, $other, $group2->id, 'Own label');
        app(SchoolGroupGovernanceService::class)->removeSchool($root2, $group2, $other, SchoolGroupMembershipReason::NETWORK_EXIT, $this->freshProof($root2));
        $this->assertNull($this->in($other, fn () => DB::table('school_brand_profiles')->where('school_id', $other->id)->value('brand_source_group_id')), 'the source is cleared atomically');
        $this->assertSame(['school', 'Own label'], [$this->brand($other)['source'], $this->brand($other)['label']]);
    }

    #[Test]
    public function the_network_brand_needs_its_own_manager_a_fresh_proof_a_reason_and_the_current_version(): void
    {
        $school = $this->createSchool();
        [$group, $manager] = $this->networkWith($school);
        [$otherGroup] = $this->networkWith($this->createSchool());
        $service = app(NetworkBrandService::class);

        $this->assertThrows(fn () => $service->update($manager, $otherGroup, 'X', null, 1, NetworkBrandChangeReason::REBRAND, $this->freshProof($manager)), AccessDeniedHttpException::class);
        $this->assertThrows(fn () => $service->update($manager, $group, 'X', null, 1, NetworkBrandChangeReason::REBRAND, $this->freshProof($this->createUser())), AuthorizationException::class);
        $this->assertThrows(fn () => $service->update($manager, $group, 'X', null, 0, NetworkBrandChangeReason::REBRAND, $this->freshProof($manager)), ValidationException::class, null);
        $this->assertThrows(fn () => $service->update($manager, $group, 'X', 'red', 1, NetworkBrandChangeReason::REBRAND, $this->freshProof($manager)), ValidationException::class);

        $this->assertSame(['display_label'], $service->update($manager, $group, 'Northstar Schools', '#1D4E89', 1, NetworkBrandChangeReason::REBRAND, $this->freshProof($manager)));
        $audit = json_decode(DB::table('platform_audit_events')->where('event_type', 'platform.school_group.brand_updated')->orderByDesc('id')->value('metadata'), true);
        $this->assertSame(['display_label'], $audit['changed']);
        $this->assertSame(['rebrand', 'network'], [$audit['reason_code'], $audit['authority']]);

        $viewer = $this->createUser();
        app(NetworkAccessService::class)->grant($manager, $group, $viewer->email, NetworkAccessService::VIEWER, null, null, NetworkRoleChangeReason::OPERATIONAL_NEED, $this->freshProof($manager));
        $this->assertThrows(fn () => $service->update($viewer, $group, 'X', null, 2, NetworkBrandChangeReason::REBRAND, $this->freshProof($viewer)), AccessDeniedHttpException::class);
        // It writes no School row and opts in nobody.
        $this->assertSame(0, $this->in($school, fn () => DB::table('school_brand_profiles')->count()));
    }

    #[Test]
    public function navigation_and_brand_cost_a_bounded_number_of_queries(): void
    {
        $plain = $this->createSchool();
        $custom = $this->createSchool();
        $admin = $this->schoolAdminFor($plain);
        $this->assignSchoolRole($this->createMembership($admin, $custom), 'school_admin');
        $changes = [];
        foreach (array_slice(array_keys(Catalogue::items(Catalogue::STAFF_WEB)), 0, 30) as $i => $key) {
            $changes[$key] = $key === 'presentation' ? ['label' => 'Look'] : ['hidden' => $i % 3 === 0, 'label' => 'L'.$i];
        }
        $this->save($admin, $custom, $changes);
        [$group] = $this->networkWith($custom);
        $this->saveBrand($admin, $custom, $group->id);

        $count = function (School $school) use ($admin): int {
            $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");
            $this->get('/app');
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->get('/app');

            return $n;
        };
        $plainCount = $count($plain);
        $customCount = $count($custom);
        $this->assertLessThanOrEqual($plainCount + 2, $customCount, "plain {$plainCount}, customized {$customCount}");
    }

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }
}
