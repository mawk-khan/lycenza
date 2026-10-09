<?php

namespace Tests\Feature\App;

use App\Domain\Visitor\Infrastructure\Visitor;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10C -- the administrative Visitor Inertia UI
 * (App\Http\Controllers\App\{VisitorController, VisitorVisitController}).
 * Backend authorization/tenant-safety/domain invariants are already
 * proven by the JSON API test suite (VisitorApiTest,
 * VisitorLifecycleTest, VisitorVisitServiceTest) -- these tests cover
 * the Inertia-specific integration: page rendering, capability-aware
 * props, and -- the checkpoint brief's explicit mandate (section 25)
 * -- that the live search/lookup endpoints powering the check-in form
 * reject an unauthorized School member exactly like the mutation
 * itself, never repeating the Phase 10A Library P1 finding. Mirrors
 * TransportAdminUiTest's exact pattern.
 */
class VisitorAdminUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantOnly(School $school, User $user, string $roleKey, string $capability): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => $roleKey, 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync([$capability]));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
        ])));
    }

    // --- Directory ----------------------------------------------------------

    #[Test]
    public function a_member_with_directory_view_sees_the_directory_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $this->createVisitor($school, ['full_name' => 'Priya Sharma']);

        $this->get('/app/visitor/directory')->assertInertia(fn ($page) => $page
            ->component('App/Visitor/Directory/Index')
            ->where('canManage', true)
            ->has('visitors.data', 1)
        );
    }

    #[Test]
    public function a_member_without_directory_view_is_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/visitor/directory')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_add_and_update_a_visitor_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/visitor/directory', ['full_name' => 'UI Visitor', 'phone' => '9999999999']);
        $response->assertRedirect();

        $visitor = app(TenantContext::class)->withSchool($school, fn () => Visitor::query()->where('full_name', 'UI Visitor')->first());
        $this->assertNotNull($visitor);

        $update = $this->patch("/app/visitor/directory/{$visitor->id}", ['status' => 'inactive']);
        $update->assertRedirect();
        $updated = app(TenantContext::class)->withSchool($school, fn () => $visitor->fresh());
        $this->assertSame('inactive', $updated->status);
    }

    #[Test]
    public function a_view_only_directory_member_cannot_add_a_visitor(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $this->grantOnly($school, $viewer, 'test_directory_viewer_ui', 'visitor.directory.view');
        $this->activate($viewer, $school);

        $this->post('/app/visitor/directory', ['full_name' => 'Denied Visitor'])->assertForbidden();
        $exists = app(TenantContext::class)->withSchool($school, fn () => Visitor::query()->where('full_name', 'Denied Visitor')->exists());
        $this->assertFalse($exists);
    }

    // --- Visits ---------------------------------------------------------------

    #[Test]
    public function a_member_with_visits_view_sees_the_visits_index(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $this->createVisitorVisit($visitor, $campus);

        $this->get('/app/visitor/visits')->assertInertia(fn ($page) => $page
            ->component('App/Visitor/Visits/Index')
            ->where('canManage', true)
            ->has('visits.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_check_in_and_check_out_a_visitor_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);

        $checkIn = $this->post('/app/visitor/visits', [
            'visitor_id' => $visitor->id, 'campus_id' => $campus->id, 'purpose' => 'UI check-in',
        ]);
        $checkIn->assertRedirect('/app/visitor/visits');

        $visit = app(TenantContext::class)->withSchool($school, fn () => VisitorVisit::query()->where('visitor_id', $visitor->id)->first());
        $this->assertNotNull($visit);
        $this->assertSame('checked_in', $visit->status);

        $checkOut = $this->post("/app/visitor/visits/{$visit->id}/end");
        $checkOut->assertRedirect('/app/visitor/visits');
        $ended = app(TenantContext::class)->withSchool($school, fn () => $visit->fresh());
        $this->assertSame('checked_out', $ended->status);
    }

    #[Test]
    public function checking_in_an_already_checked_in_visitor_via_the_ui_shows_a_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campusOne = $this->createCampus($school);
        $campusTwo = $this->createCampus($school);
        $visitor = $this->createVisitor($school);

        $this->post('/app/visitor/visits', ['visitor_id' => $visitor->id, 'campus_id' => $campusOne->id, 'purpose' => 'First'])->assertRedirect();

        $second = $this->post('/app/visitor/visits', ['visitor_id' => $visitor->id, 'campus_id' => $campusTwo->id, 'purpose' => 'Second']);
        $second->assertSessionHasErrors('visitor_id');
    }

    /**
     * THE anti-P1 regression (checkpoint brief section 25, explicitly
     * citing the Phase 10A Library finding): the check-in form's live
     * search endpoints must enforce visitor.visits.manage exactly like
     * the check-in action itself -- an unauthorized School member must
     * never be able to enumerate Visitor names/contact data or
     * Employee host data through these endpoints.
     */
    #[Test]
    public function a_member_without_visits_manage_cannot_use_the_check_in_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);
        $this->createVisitor($school, ['full_name' => 'Findable Visitor', 'phone' => '5551234567']);
        $this->createEmployee($school, ['full_name' => 'Findable Host']);

        $this->get('/app/visitor/visits/search/visitors?q=Findable')->assertForbidden();
        $this->get('/app/visitor/visits/search/hosts?q=Findable')->assertForbidden();
    }

    #[Test]
    public function a_visits_manage_member_can_use_the_check_in_search_endpoints(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createVisitor($school, ['full_name' => 'Findable Visitor']);
        $this->createEmployee($school, ['full_name' => 'Findable Host']);

        $this->get('/app/visitor/visits/search/visitors?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->get('/app/visitor/visits/search/hosts?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_wrong_school_visit_id_is_not_found_via_the_ui(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $schoolA);
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $visitorB = $this->createVisitor($schoolB);
        $visitB = $this->createVisitorVisit($visitorB, $campusB);

        $this->post("/app/visitor/visits/{$visitB->id}/end")->assertNotFound();
    }
}
