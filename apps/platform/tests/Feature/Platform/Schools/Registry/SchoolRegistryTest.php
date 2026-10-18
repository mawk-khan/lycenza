<?php

namespace Tests\Feature\Platform\Schools\Registry;

use App\Domain\Platform\Application\Audit\PlatformAuditLogReviewService;
use App\Domain\Platform\Application\Schools\Registry\SchoolReadiness;
use App\Domain\Platform\Application\Schools\Registry\SchoolRegistryReadService;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Domains\DomainState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Schools\SchoolLifecycleTestHelpers;
use Tests\TestCase;

/**
 * ORG.3 (ADR 0076 §9.2-§9.3): the Platform School Registry -- who may see
 * it, what it shows (non-business metadata only), search, filters, keyset
 * pagination, derived readiness and warnings, and the School-scoped platform
 * audit summary.
 */
class SchoolRegistryTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesSchoolDomains, CreatesTenancyFixtures, SchoolLifecycleTestHelpers;

    /** @return array<string, mixed> */
    private function listPage(User $actor, array $query = []): array
    {
        return $this->actingAs($actor)->get('/app/platform/schools?'.http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function row(array $page, School $school): array
    {
        return collect($page['schools'])->firstWhere('id', $school->id) ?? $this->fail("{$school->name} is not on the page");
    }

    /** A School that passes every readiness check. */
    private function readySchool(array $attributes = []): School
    {
        [, $school] = $this->createSchoolAdmin();
        $school->forceFill($attributes)->save();
        $this->createCampus($school);
        $this->createAcademicYear($school, ['status' => 'active']);

        return $school->fresh();
    }

    // -- Authorization --------------------------------------------------------

    #[Test]
    public function the_platform_root_sees_the_registry_and_an_overview(): void
    {
        $root = $this->platformAdmin();
        $school = $this->readySchool(['name' => 'Registry Ready']);

        $page = $this->listPage($root);
        $this->assertSame('ready', $this->row($page, $school)['readiness']);
        $this->assertTrue($page['canCreate']);

        $this->actingAs($root)->get("/app/platform/schools/{$school->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/Schools/Show')
                ->where('registry.state', SchoolReadiness::READY)
                ->where('canManage', true)
                ->where('actions.suspend', true));
    }

    #[Test]
    public function school_authority_never_reaches_the_registry(): void
    {
        $school = $this->readySchool();
        [$schoolAdmin] = $this->createSchoolAdmin();
        [$principal] = $this->createSchoolAdmin('principal');
        [$teacher] = $this->createSchoolAdmin('teacher');
        $guardian = $this->portalGuardian($school)['user'];

        foreach ([$schoolAdmin, $principal, $teacher, $guardian] as $actor) {
            $this->actingAs($actor)->get('/app/platform/schools')->assertForbidden();
            $this->actingAs($actor)->get("/app/platform/schools/{$school->id}")->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->get('/app/platform/schools')->assertRedirect('/login');
    }

    #[Test]
    public function the_platform_auditor_reads_the_registry_and_changes_nothing(): void
    {
        // ORG.4 (ADR 0076 §8): read-only Registry oversight.
        $root = $this->platformAdmin();
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor', $root);
        $school = $this->readySchool();

        $this->assertFalse($this->listPage($auditor)['canCreate']);
        $this->actingAs($auditor)->get("/app/platform/schools/{$school->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('canManage', false)->where('canOnboard', false)
                ->where('actions', ['activate' => false, 'replaceBootstrapAdmin' => false, 'suspend' => false, 'resume' => false, 'close' => false, 'reopen' => false]));
        $this->actingAs($auditor)->get('/app/platform/schools/create')->assertForbidden();
        $this->actingAs($auditor)->get("/app/platform/schools/{$school->id}/suspend")->assertForbidden();
    }

    #[Test]
    public function platform_and_school_grants_never_merge(): void
    {
        // A School Administrator who is also the platform Auditor reads the
        // Registry through the platform grant only: no onboarding or lifecycle
        // authority appears, and the School grant adds nothing platform-side.
        $root = $this->platformAdmin();
        [$dual, $school] = $this->createSchoolAdmin();
        $this->assignPlatformRole($dual, 'platform_auditor', $root);

        $this->assertFalse($this->listPage($dual)['canCreate']);
        $this->actingAs($dual)->get("/app/platform/schools/{$school->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('canManage', false)->where('canOnboard', false));
        $this->actingAs($dual)->get('/app/platform/schools/create')->assertForbidden();
    }

    #[Test]
    public function a_viewer_without_lifecycle_authority_sees_no_actions_and_still_cannot_act(): void
    {
        $root = $this->platformAdmin();
        $school = $this->readySchool();
        // A view-only operator without inventing a role: platform.schools.manage
        // and platform.schools.onboard are denied, platform.schools.view holds.
        Gate::before(fn (User $user, string $ability, array $arguments) => $ability === 'capability' && in_array($arguments[0] ?? null, ['platform.schools.manage', 'platform.schools.onboard'], true) ? false : null);

        $this->actingAs($root)->get("/app/platform/schools/{$school->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('canManage', false)->where('actions.suspend', false));
        $this->assertFalse($this->listPage($root)['canCreate']);
        $this->actingAs($root)->get('/app/platform/schools/create')->assertForbidden();
        $this->actingAs($root)->get("/app/platform/schools/{$school->id}/suspend")->assertForbidden();
    }

    // -- What it shows ---------------------------------------------------------

    #[Test]
    public function the_registry_shows_metadata_and_no_tenant_business_data(): void
    {
        $root = $this->platformAdmin();
        $school = $this->readySchool(['name' => 'Metadata School', 'code' => 'MDS']);
        $student = $this->createStudent($school, ['first_name' => 'Zebediah']);

        $list = $this->actingAs($root)->get('/app/platform/schools?q=Metadata');
        $show = $this->actingAs($root)->get("/app/platform/schools/{$school->id}");

        foreach ([$list->getContent(), $show->getContent()] as $html) {
            $this->assertStringNotContainsString('Zebediah', $html);
            $this->assertStringNotContainsString($student->id, $html);
        }
        $overview = $show->viewData('page')['props']['registry'];
        $this->assertSame(['active' => 1, 'inactive' => 0], $overview['campuses']);
        $this->assertNotNull($overview['academicYear']);
        $this->assertSame('ready', $overview['administrator']);
        // Administrator readiness is a yes/no -- no identity once the School is active.
        $this->assertSame([], $show->viewData('page')['props']['bootstrapAdmins']);
        $this->assertArrayNotHasKey('email', $overview);
    }

    // -- Search, filters, pagination -------------------------------------------

    #[Test]
    public function search_covers_name_legal_name_slug_and_code_case_insensitively(): void
    {
        $root = $this->platformAdmin();
        $a = $this->createSchool(['name' => 'Harbour Lights Academy', 'slug' => 'harbour-lights']);
        $b = $this->createSchool(['name' => 'North Ridge', 'legal_name' => 'Ridge Education Trust Ltd']);
        $c = $this->createSchool(['name' => 'Elm Park', 'code' => 'ELMP77']);

        $ids = fn (string $q) => array_column($this->listPage($root, ['q' => $q])['schools'], 'id');

        $this->assertSame([$a->id], $ids('HARBOUR'));
        $this->assertSame([$a->id], $ids('lights'));
        $this->assertSame([$b->id], $ids('education trust'));
        $this->assertSame([$c->id], $ids('elmp7'));
        $this->assertSame([], $ids('no such school'));
        // Wildcards are literal characters, never patterns.
        $this->assertSame([], $ids('%'));
        $this->assertSame([], $ids('_'));
        $this->assertSame([], $ids("'; drop table schools; --"));

        $this->actingAs($root)->get('/app/platform/schools?q='.str_repeat('x', 101))->assertSessionHasErrors('q');
    }

    #[Test]
    public function filters_narrow_by_lifecycle_group_and_domain_and_combine(): void
    {
        $root = $this->platformAdmin();
        $active = $this->createSchool(['name' => 'Filter Active']);
        $provisioning = School::factory()->provisioning()->create(['name' => 'Filter Provisioning']);
        $suspended = School::factory()->suspended()->create(['name' => 'Filter Suspended']);
        $closed = School::factory()->suspended()->create(['name' => 'Filter Closed']);
        $closed->forceFill(['closed_at' => now(), 'closure_reason' => 'contract_ended'])->save();
        $group = $this->createGroup([$active, $suspended], 'Filter Trust');
        $this->domain($active, DomainState::Active, true);
        $this->domain($suspended, DomainState::Suspended);
        $this->domain($provisioning, DomainState::PendingVerification);

        $ids = fn (array $q) => array_column($this->listPage($root, $q + ['q' => 'Filter'])['schools'], 'id');

        $this->assertSame([$active->id], $ids(['status' => 'active']));
        $this->assertSame([$provisioning->id], $ids(['status' => 'provisioning']));
        $this->assertSame([$suspended->id], $ids(['status' => 'suspended']));
        $this->assertSame([$closed->id], $ids(['status' => 'closed']));
        $this->assertSame([$active->id, $suspended->id], $ids(['group' => $group->id]));
        $this->assertSame([$active->id], $ids(['domain' => 'active']));
        $this->assertSame([$suspended->id], $ids(['domain' => 'attention']));
        $this->assertSame([$provisioning->id], $ids(['domain' => 'pending']));
        $this->assertSame([$closed->id], $ids(['domain' => 'none']));
        $this->assertSame([$suspended->id], $ids(['group' => $group->id, 'status' => 'suspended']));
        $this->assertSame([], $ids(['group' => $group->id, 'domain' => 'pending']));

        foreach (['status' => 'archived', 'domain' => 'broken', 'group' => 'not-a-uuid', 'per_page' => 500, 'cursor' => 'forged'] as $key => $value) {
            $this->actingAs($root)->get('/app/platform/schools?'.http_build_query([$key => $value]))->assertSessionHasErrors($key);
        }
    }

    #[Test]
    public function keyset_pagination_is_deterministic_and_complete(): void
    {
        $root = $this->platformAdmin();
        $created = [];
        foreach (range(1, 23) as $i) {
            // Equal names exercise the id tie-break.
            $created[] = $this->createSchool(['name' => 'Paged '.str_pad((string) intdiv($i, 2), 2, '0', STR_PAD_LEFT)])->id;
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->listPage($root, array_filter(['q' => 'Paged', 'per_page' => 10, 'cursor' => $cursor]));
            $this->assertLessThanOrEqual(10, count($page['schools']));
            $seen = [...$seen, ...array_column($page['schools'], 'id')];
            $cursor = $page['nextCursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertSame(count($seen), count(array_unique($seen)), 'no School appears twice');
        $this->assertEqualsCanonicalizing($created, $seen, 'no School is skipped');
        $expected = School::query()->whereIn('id', $created)->orderBy('name')->orderBy('id')->pluck('id')->all();
        $this->assertSame($expected, $seen, 'order is name, then id');
        // The same request always returns the same page.
        $this->assertSame(
            array_column($this->listPage($root, ['q' => 'Paged', 'per_page' => 10])['schools'], 'id'),
            array_slice($expected, 0, 10),
        );
        $this->actingAs($root)->get('/app/platform/schools?per_page=5')->assertSessionHasErrors('per_page');
    }

    // -- Readiness and warnings --------------------------------------------------

    #[Test]
    public function readiness_and_warnings_are_derived_for_each_kind_of_school(): void
    {
        $root = $this->platformAdmin();

        // A. provisioning with minimal setup
        $provisioning = School::factory()->provisioning()->create(['name' => 'Ready A']);
        // B. active, fully ready, F. with several Campuses
        $ready = $this->readySchool(['name' => 'Ready B']);
        $this->createCampus($ready);
        $this->createCampus($ready, ['status' => 'inactive']);
        // C. active without an active academic year
        [, $noYear] = $this->createSchoolAdmin();
        $noYear->forceFill(['name' => 'Ready C'])->save();
        $this->createCampus($noYear);
        $this->createAcademicYear($noYear, ['status' => 'draft']);
        // D. active without an administrator
        $noAdmin = $this->createSchool(['name' => 'Ready D']);
        $this->createCampus($noAdmin);
        $this->createAcademicYear($noAdmin, ['status' => 'active']);
        // E. suspended
        $suspended = $this->readySchool(['name' => 'Ready E']);
        $suspended->forceFill(['status' => 'suspended'])->save();
        // G. a suspended custom domain
        $domainIssue = $this->readySchool(['name' => 'Ready G']);
        $this->domain($domainIssue, DomainState::Suspended);
        config(['domains.enabled' => true]);

        $page = $this->listPage($root, ['q' => 'Ready ']);
        $expect = [
            [$provisioning, SchoolReadiness::PARTIALLY_CONFIGURED],
            [$ready, SchoolReadiness::READY],
            [$noYear, SchoolReadiness::NEEDS_ATTENTION],
            [$noAdmin, SchoolReadiness::NEEDS_ATTENTION],
            [$suspended, SchoolReadiness::READY],
            [$domainIssue, SchoolReadiness::NEEDS_ATTENTION],
        ];
        foreach ($expect as [$school, $state]) {
            $this->assertSame($state, $this->row($page, $school)['readiness'], $school->name);
        }
        $this->assertSame(['active' => 2, 'inactive' => 1], $this->row($page, $ready)['campuses']);

        $warnings = fn (School $s) => array_column($this->actingAs($root)->get("/app/platform/schools/{$s->id}")->viewData('page')['props']['registry']['warnings'], 'key');
        $this->assertSame(['awaiting_activation', 'administrator_missing', 'no_active_campus', 'no_active_academic_year'], $warnings($provisioning));
        $this->assertSame([], $warnings($ready));
        $this->assertSame(['no_active_academic_year'], $warnings($noYear));
        $this->assertSame(['administrator_missing'], $warnings($noAdmin));
        $this->assertSame(['school_suspended'], $warnings($suspended));
        $this->assertSame(['custom_domain_suspended'], $warnings($domainIssue));
        // Deterministic: the same School gives the same answer twice.
        $this->assertSame($warnings($provisioning), $warnings($provisioning));
        $this->assertSame(0, $this->row($page, $ready)['warningCount']);
        $this->assertSame(3, $this->row($page, $provisioning)['warningCount']);
    }

    #[Test]
    public function an_administrator_who_has_not_activated_is_named_only_as_a_state(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool(['name' => 'Pending Admin']);
        $pending = $this->createUser(['password' => null]);
        $this->assignSchoolRole($this->createMembership($pending, $school), 'school_admin');

        $overview = $this->actingAs($root)->get("/app/platform/schools/{$school->id}")->viewData('page')['props']['registry'];
        $this->assertSame('not_activated', $overview['administrator']);
        $this->assertContains('administrator_not_activated', array_column($overview['warnings'], 'key'));
        $this->assertStringNotContainsString((string) $pending->email, json_encode($overview));
    }

    #[Test]
    public function readiness_is_never_stored(): void
    {
        foreach (['readiness', 'readiness_status', 'readiness_state'] as $column) {
            $this->assertFalse(Schema::hasColumn('schools', $column), "schools.{$column} must not exist (ADR 0076 §9.3)");
        }
    }

    // -- Platform audit summary --------------------------------------------------

    #[Test]
    public function recent_platform_events_need_the_audit_review_and_are_envelopes_only(): void
    {
        $root = $this->platformAdmin();
        $school = $this->activeSchoolViaPlatform($root, $this->createUser());

        // No current MFA assurance (a new session): no events, no access record.
        $this->flushSession();
        $props = $this->actingAs($root)->get("/app/platform/schools/{$school->id}")->viewData('page')['props'];
        $this->assertSame(['available' => false, 'reason' => 'mfa', 'entries' => []], $props['audit']);
        $this->assertSame(0, PlatformAuditEvent::query()->where('event_type', PlatformAuditLogReviewService::ACCESS_EVENT)->count());

        $props = $this->actingAs($root)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->get("/app/platform/schools/{$school->id}")->viewData('page')['props'];
        $types = array_column($props['audit']['entries'], 'eventType');
        $this->assertContains('platform.school.created', $types);
        $this->assertContains('platform.school.activated', $types);
        foreach ($props['audit']['entries'] as $entry) {
            $this->assertSame(['id', 'occurredAt', 'eventType', 'actorUserId', 'subjectType', 'subjectId', 'requestId'], array_keys($entry));
            $this->assertSame($school->id, $entry['subjectId']);
            $this->assertStringStartsWith('platform.school', $entry['eventType']);
        }
        // Exactly one access record, naming the School.
        $access = PlatformAuditEvent::query()->where('event_type', PlatformAuditLogReviewService::ACCESS_EVENT)->sole();
        $this->assertSame($school->id, $access->getAttribute('subject_id'));
        $this->assertSame(['paged' => false, 'resultCount' => count($types)], $access->metadata);
    }

    #[Test]
    public function the_overview_never_reads_the_school_audit_ledger(): void
    {
        $root = $this->platformAdmin();
        $school = $this->readySchool();

        DB::enableQueryLog();
        $this->actingAs($root)->withSession(['mfa_verified_at' => now()->toIso8601String()])->get("/app/platform/schools/{$school->id}")->assertOk();
        $this->actingAs($root)->get('/app/platform/schools')->assertOk();
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('school_audit_events', $sql);
        foreach (['students', 'guardians', 'employees', 'charges', 'payments', 'payroll_runs', 'student_marks', 'documents'] as $table) {
            $this->assertDoesNotMatchRegularExpression('/\bfrom "'.$table.'"/', $sql, "the registry must not read {$table}");
        }
    }

    private function domain(School $school, DomainState $state, bool $primary = false): void
    {
        $this->createSchoolDomain($school, 'erp-'.Str::lower(Str::random(8)).'.example.test', $state, $primary ?: null);
    }

    #[Test]
    public function page_size_and_search_length_are_bounded(): void
    {
        $this->assertSame(50, SchoolRegistryReadService::MAX_PER_PAGE);
        $this->assertSame(100, SchoolRegistryReadService::MAX_SEARCH_LENGTH);
    }
}
