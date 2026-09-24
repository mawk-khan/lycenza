<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.1 (D9(a)/D10(a), docs/architecture/PHASE-0N-READINESS.md
 * section 11): School-scoped web routes require a valid selected School
 * before anything School-scoped runs (App\Http\Middleware\RequireSchoolContext);
 * /app is the context-neutral landing. Route coverage and middleware
 * ordering are guarded separately by SchoolContextRouteGuardTest.
 */
class SchoolContextRequiredTest extends TestCase
{
    use CreatesTenancyFixtures;

    private int $executed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only School route, so "the controller never ran" is
        // observable directly rather than inferred from a status code.
        RouteFacade::middleware(['web', 'auth', 'school-context'])
            ->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/__test/school-only', function () {
                $this->executed++;

                return response()->json(['school' => app(TenantContext::class)->requireSchool()->id]);
            });
    }

    // --- The /app landing --------------------------------------------------

    #[Test]
    public function a_member_with_no_school_selected_gets_the_landing_with_their_memberships_and_no_school_selected(): void
    {
        $user = $this->createUser();
        $schoolA = $this->createSchool(['name' => 'Alpha School']);
        $schoolB = $this->createSchool(['name' => 'Beta School']);
        $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        $this->actingAs($user)->get('/app')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Dashboard')
                ->where('activeSchool', null)
                ->where('schoolContextNotice', null)
                ->where('platformAccount', false)
                ->has('memberships', 2)
                ->where('memberships.0.isActive', false)
                ->where('memberships.1.isActive', false)
                ->where('nav.canViewStudents', false)
            );

        // Rendering the landing never selects a School.
        $this->assertNull(session('active_school_id'));
    }

    #[Test]
    public function an_account_with_no_school_membership_gets_a_neutral_landing(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->get('/app')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Dashboard')
                ->where('activeSchool', null)
                ->where('platformAccount', false)
                ->has('memberships', 0)
            );
    }

    #[Test]
    public function a_platform_super_admin_gets_a_neutral_landing_with_no_school_and_no_school_data(): void
    {
        $this->createSchool(['name' => 'Someone Else School']);
        $admin = $this->createUser();
        $this->assignPlatformRole($admin, 'platform_super_admin');

        $response = $this->actingAs($admin)->get('/app');

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Dashboard')
                ->where('activeSchool', null)
                ->where('platformAccount', true)
                ->has('memberships', 0)
                ->where('nav.canViewStudents', false)
                ->where('nav.canViewSchoolSettings', false)
            );
        $this->assertStringNotContainsString('Someone Else School', $response->getContent());
        $this->assertSame(0, SchoolMembership::query()->where('user_id', $admin->id)->count());
        $this->assertNull(session('active_school_id'));
    }

    // --- GET/HEAD without a School ------------------------------------------

    #[Test]
    public function a_page_request_without_a_school_returns_to_the_landing_without_running_the_controller(): void
    {
        $user = $this->createUser();
        $this->createMembership($user, $this->createSchool());

        $this->actingAs($user)->get('/__test/school-only')->assertRedirect('/app');
        $this->assertSame(0, $this->executed);

        $this->get('/app')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('schoolContextNotice', 'select')
            ->where('activeSchool', null)
        );

        // One-request notice.
        $this->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('schoolContextNotice', null));
    }

    #[Test]
    public function an_inertia_page_visit_and_a_head_request_without_a_school_also_return_to_the_landing(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->get('/app/students', $this->inertiaHeaders())->assertRedirect('/app');
        $this->call('HEAD', '/app/students')->assertRedirect('/app');
        $this->assertSame(0, $this->executed);
    }

    #[Test]
    public function a_json_request_without_a_school_gets_the_stable_409_refusal(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->getJson('/__test/school-only')
            ->assertStatus(409)
            ->assertJsonPath('error.code', RequireSchoolContext::ERROR_CODE)
            ->assertJsonPath('error.status', 409)
            ->assertJsonPath('error.message', 'Select a School to continue.');
        $this->assertSame(0, $this->executed);
    }

    #[Test]
    public function a_school_scoped_route_model_is_not_bound_before_the_school_context_check(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $user = $this->createUser();
        $this->createMembership($user, $school);

        // Not a 404 from binding the Student with no School: the
        // prerequisite runs first.
        $this->actingAs($user)->get("/app/students/{$student->id}")->assertRedirect('/app');
    }

    #[Test]
    public function the_three_previously_forbidden_routes_now_return_to_the_landing_before_their_capability_check(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        foreach (['/app/settings', '/app/automation', '/app/compliance/audit-log'] as $uri) {
            $this->get($uri)->assertRedirect('/app');
        }
    }

    // --- Mutations without a School ------------------------------------------

    #[Test]
    public function an_inertia_mutation_without_a_school_fails_closed_with_409_and_a_hard_visit_to_the_landing(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $response = $this->{$method}('/__test/school-only', [], $this->inertiaHeaders());

            $response->assertStatus(409);
            $response->assertHeader('X-Inertia-Location', route('app.dashboard'));
        }
        $this->assertSame(0, $this->executed);

        $this->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('schoolContextNotice', 'not_saved'));
    }

    #[Test]
    public function a_json_mutation_and_a_plain_form_mutation_without_a_school_fail_closed_with_409(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->postJson('/__test/school-only', ['x' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', RequireSchoolContext::ERROR_CODE);

        $this->post('/__test/school-only', ['x' => 1])->assertStatus(409);

        $this->assertSame(0, $this->executed);
    }

    #[Test]
    public function a_real_school_mutation_without_a_school_changes_nothing(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $before = $school->name;

        $this->actingAs($user)
            ->put('/app/settings', ['name' => 'Renamed Without Context', 'timezone' => $school->timezone, 'default_locale' => $school->default_locale])
            ->assertStatus(409);

        $this->assertSame($before, $school->fresh()->name);
    }

    // --- With a valid School -------------------------------------------------

    #[Test]
    public function a_selected_school_with_an_active_membership_reaches_the_controller(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->actingAs($user)->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');

        $this->getJson('/__test/school-only')->assertOk()->assertJsonPath('school', $school->id);
        $this->postJson('/__test/school-only')->assertOk();
        $this->assertSame(2, $this->executed);
    }

    #[Test]
    public function a_genuine_authorization_denial_is_still_403_once_a_school_is_selected(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->get('/app/settings')->assertForbidden();
    }

    // --- Stale / invalid selected context -------------------------------------

    #[Test]
    public function a_selection_whose_membership_was_suspended_is_cleared_and_not_entered(): void
    {
        [$user, $school, $membership] = $this->selectedMember();

        $membership->update(['status' => 'suspended']);

        $this->assertStaleSelectionHandled($user);
    }

    #[Test]
    public function a_selection_whose_membership_was_removed_is_cleared_and_not_entered(): void
    {
        [$user, $school, $membership] = $this->selectedMember();

        $membership->roleAssignments()->delete();
        $membership->delete();

        $this->assertStaleSelectionHandled($user);
    }

    #[Test]
    public function a_selection_whose_school_was_suspended_or_archived_is_cleared_and_not_entered(): void
    {
        foreach (['suspended', 'archived'] as $status) {
            [$user, $school] = $this->selectedMember();

            $school->update(['status' => $status]);

            $this->assertStaleSelectionHandled($user);
        }
    }

    #[Test]
    public function a_disabled_account_does_not_keep_its_selected_school(): void
    {
        [$user] = $this->selectedMember();

        $user->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $this->assertStaleSelectionHandled($user->fresh());
    }

    #[Test]
    public function a_nonexistent_or_corrupt_session_school_id_is_cleared_and_never_errors(): void
    {
        $user = $this->createUser();
        $this->createMembership($user, $this->createSchool());

        foreach ([(string) Str::uuid(), 'not-a-uuid', '', '0'] as $value) {
            $this->actingAs($user)->withSession(['active_school_id' => $value]);

            $this->get('/app/students')->assertRedirect('/app');
            $this->assertNull(session('active_school_id'));
            $this->get('/app')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('activeSchool', null));
        }
        $this->assertSame(0, $this->executed);
    }

    #[Test]
    public function a_stale_selection_never_falls_back_to_another_school_the_user_belongs_to(): void
    {
        [$user, $schoolA, $membershipA] = $this->selectedMember();
        $schoolB = $this->createSchool();
        $this->createMembership($user, $schoolB);

        $membershipA->update(['status' => 'suspended']);

        $this->actingAs($user)->get('/__test/school-only')->assertRedirect('/app');
        $this->get('/app')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeSchool', null)
            ->has('memberships', 1)
            ->where('memberships.0.schoolId', $schoolB->id)
            ->where('memberships.0.isActive', false)
        );
        $this->assertNull(session('active_school_id'));
    }

    #[Test]
    public function the_landing_itself_clears_a_stale_selection_so_a_reactivated_membership_does_not_reselect_the_school(): void
    {
        [$user, $school, $membership] = $this->selectedMember();

        $membership->update(['status' => 'suspended']);
        $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('activeSchool', null));
        $this->assertNull(session('active_school_id'));

        $membership->update(['status' => 'active']);
        $this->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('activeSchool', null));
        $this->get('/__test/school-only')->assertRedirect('/app');
    }

    // --- Platform Super Admin -------------------------------------------------

    #[Test]
    public function a_platform_super_admin_gets_no_implicit_school_access(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $admin = $this->createUser();
        $this->assignPlatformRole($admin, 'platform_super_admin');
        $this->actingAs($admin);

        $this->get('/app/students')->assertRedirect('/app');
        $this->get("/app/students/{$student->id}")->assertRedirect('/app');
        $this->postJson('/__test/school-only')->assertStatus(409);

        // Explicit selection is still refused without a real membership.
        $this->post("/app/schools/{$school->id}/activate")->assertSessionHasErrors('school');
        $this->assertNull(session('active_school_id'));

        // Nor does a session value it could never have obtained work.
        $this->withSession(['active_school_id' => $school->id]);
        $this->get('/__test/school-only')->assertRedirect('/app');
        $this->assertNull(session('active_school_id'));
        $this->assertSame(0, $this->executed);
    }

    // --- Multi-School member --------------------------------------------------

    #[Test]
    public function a_multi_school_member_selects_explicitly_and_each_school_stays_isolated(): void
    {
        $user = $this->createUser();
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->assignSchoolRole($this->createMembership($user, $schoolA), 'school_admin');
        $this->assignSchoolRole($this->createMembership($user, $schoolB), 'school_admin');
        $studentA = $this->createStudent($schoolA);
        $studentB = $this->createStudent($schoolB);
        $this->actingAs($user);

        $this->get("/app/students/{$studentA->id}")->assertRedirect('/app');
        $this->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('activeSchool', null)->has('memberships', 2));

        $this->post("/app/schools/{$schoolA->id}/activate")->assertRedirect('/app');
        $this->get("/app/students/{$studentA->id}")->assertOk();
        $this->get("/app/students/{$studentB->id}")->assertNotFound();

        $this->post("/app/schools/{$schoolB->id}/activate")->assertRedirect('/app');
        $this->get("/app/students/{$studentB->id}")->assertOk();
        $this->get("/app/students/{$studentA->id}")->assertNotFound();

        $this->assertSame(2, PlatformAuditEvent::query()
            ->where('event_type', 'school_context.activated')
            ->where('actor_user_id', $user->id)
            ->count());
    }

    #[Test]
    public function school_selection_still_regenerates_the_session_and_establishes_only_the_selected_school(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $other = $this->createSchool();
        $this->createMembership($user, $school);
        $this->actingAs($user);

        $before = session()->getId();
        $this->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');
        $this->assertNotSame($before, session()->getId());

        $this->post("/app/schools/{$other->id}/activate")->assertSessionHasErrors('school');
        $this->assertSame($school->id, session('active_school_id'));
        $this->getJson('/__test/school-only')->assertJsonPath('school', $school->id);
    }

    // --- /api/v1 is unchanged -------------------------------------------------

    #[Test]
    public function the_api_still_derives_the_school_from_the_url_without_any_session_selection(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $stranger = $this->createSchool();
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/schools/{$school->id}/context")
            ->assertOk()
            ->assertJsonPath('data.school.id', $school->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/schools/{$stranger->id}/context")
            ->assertNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    /**
     * @return array{0: User, 1: School, 2: SchoolMembership}
     */
    private function selectedMember(): array
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);

        $this->actingAs($user)->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');
        $this->getJson('/__test/school-only')->assertOk();
        $this->executed = 0;

        return [$user, $school, $membership];
    }

    private function assertStaleSelectionHandled(User $user): void
    {
        $this->actingAs($user);

        $this->get('/__test/school-only')->assertRedirect('/app');
        $this->assertNull(session('active_school_id'));
        $this->assertSame(0, $this->executed);

        $this->get('/app')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeSchool', null)
            ->where('schoolContextNotice', 'select')
        );
    }
}
