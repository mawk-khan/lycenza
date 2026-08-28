<?php

namespace Tests\Feature\App;

use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10D -- the administrative Hostel Inertia UI
 * (App\Http\Controllers\App\{HostelController, HostelRoomController,
 * HostelResidencyController}). Backend authorization/tenant-safety/
 * domain invariants are already proven by the JSON API test suite
 * (HostelApiTest, HostelLifecycleTest, HostelResidencyServiceTest) --
 * these tests cover the Inertia-specific integration: page rendering,
 * capability-aware props, and -- carrying forward the Phase 10A
 * Library/10B Transport/10C Visitor precedent -- that the residency
 * assign form's live Student/Bed search endpoints reject an
 * unauthorized School member exactly like the assign action itself.
 * Mirrors VisitorAdminUiTest's exact pattern.
 */
class HostelAdminUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantOnly(School $school, User $user, string $roleKey, string $capability): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => $roleKey, 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync([$capability]);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
        ]));
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
        $campus = $this->createCampus($school);
        $this->createHostel($school, $campus, ['name' => 'Boys Hostel']);

        $this->get('/app/hostels')->assertInertia(fn ($page) => $page
            ->component('App/Hostel/Directory/Index')
            ->where('canManage', true)
            ->has('hostels.data', 1)
        );
    }

    #[Test]
    public function a_member_without_directory_view_is_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/hostels')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_add_and_update_a_hostel_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);

        $response = $this->post('/app/hostels', ['code' => 'ui-hostel', 'name' => 'UI Hostel', 'campus_id' => $campus->id]);
        $response->assertRedirect();

        $hostel = app(TenantContext::class)->withSchool($school, fn () => Hostel::query()->where('code', 'UI-HOSTEL')->first());
        $this->assertNotNull($hostel);

        $update = $this->patch("/app/hostels/{$hostel->id}", ['status' => 'inactive']);
        $update->assertRedirect();
        $updated = app(TenantContext::class)->withSchool($school, fn () => $hostel->fresh());
        $this->assertSame('inactive', $updated->status);
    }

    #[Test]
    public function a_view_only_directory_member_cannot_add_a_hostel(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $viewer = $this->createUser();
        $this->grantOnly($school, $viewer, 'test_directory_viewer_ui', 'hostel.directory.view');
        $this->activate($viewer, $school);

        $this->post('/app/hostels', ['code' => 'DENIED', 'name' => 'Denied', 'campus_id' => $campus->id])->assertForbidden();
        $exists = app(TenantContext::class)->withSchool($school, fn () => Hostel::query()->where('code', 'DENIED')->exists());
        $this->assertFalse($exists);
    }

    // --- Room / Bed management ------------------------------------------------

    #[Test]
    public function a_school_admin_can_view_a_hostel_and_add_a_room(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);

        $this->get("/app/hostels/{$hostel->id}")->assertInertia(fn ($page) => $page
            ->component('App/Hostel/Show/Index')
            ->where('hostel.id', $hostel->id)
            ->where('canManage', true)
        );

        $response = $this->post("/app/hostels/{$hostel->id}/rooms", ['code' => 'room-1']);
        $response->assertRedirect("/app/hostels/{$hostel->id}");

        $room = app(TenantContext::class)->withSchool($school, fn () => HostelRoom::query()->where('code', 'ROOM-1')->first());
        $this->assertNotNull($room);
    }

    #[Test]
    public function a_school_admin_can_view_a_room_add_a_bed_and_toggle_its_status(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);

        $this->get("/app/hostel-rooms/{$room->id}")->assertInertia(fn ($page) => $page
            ->component('App/Hostel/Show/Room')
            ->where('room.id', $room->id)
            ->has('beds', 0)
        );

        $store = $this->post("/app/hostel-rooms/{$room->id}/beds", ['code' => 'bed-1']);
        $store->assertRedirect("/app/hostel-rooms/{$room->id}");

        $bed = app(TenantContext::class)->withSchool($school, fn () => HostelBed::query()->where('code', 'BED-1')->first());
        $this->assertNotNull($bed);

        $toggle = $this->patch("/app/hostel-beds/{$bed->id}", ['status' => 'inactive']);
        $toggle->assertRedirect("/app/hostel-rooms/{$room->id}");
        $updated = app(TenantContext::class)->withSchool($school, fn () => $bed->fresh());
        $this->assertSame('inactive', $updated->status);
    }

    // --- Residency --------------------------------------------------------------

    #[Test]
    public function a_member_with_residency_view_sees_the_residency_index(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $this->createHostelResidencyAssignment($student, $bed);

        $this->get('/app/hostel-residency')->assertInertia(fn ($page) => $page
            ->component('App/Hostel/Residency/Index')
            ->where('canManage', true)
            ->has('assignments.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_assign_and_end_a_residency_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $assign = $this->post('/app/hostel-residency', ['student_id' => $student->id, 'hostel_bed_id' => $bed->id]);
        $assign->assertRedirect('/app/hostel-residency');

        $assignment = app(TenantContext::class)->withSchool($school, fn () => HostelResidencyAssignment::query()->where('student_id', $student->id)->first());
        $this->assertNotNull($assignment);
        $this->assertSame('active', $assignment->status);

        $end = $this->post("/app/hostel-residency/{$assignment->id}/end");
        $end->assertRedirect('/app/hostel-residency');
        $ended = app(TenantContext::class)->withSchool($school, fn () => $assignment->fresh());
        $this->assertSame('ended', $ended->status);
    }

    #[Test]
    public function assigning_an_already_resident_student_via_the_ui_shows_a_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bedOne = $this->createHostelBed($room);
        $bedTwo = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->post('/app/hostel-residency', ['student_id' => $student->id, 'hostel_bed_id' => $bedOne->id])->assertRedirect();

        $second = $this->post('/app/hostel-residency', ['student_id' => $student->id, 'hostel_bed_id' => $bedTwo->id]);
        $second->assertSessionHasErrors('student_id');
    }

    /**
     * THE anti-P1 regression carried forward from Library/Transport/
     * Visitor: the assign form's live search endpoints must enforce
     * hostel.residency.manage exactly like the assign action itself --
     * an unauthorized School member must never be able to enumerate
     * Student identity data or available-Bed data through these
     * endpoints.
     */
    #[Test]
    public function a_member_without_residency_manage_cannot_use_the_assign_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);
        $this->createStudent($school, ['status' => 'active', 'first_name' => 'Findable', 'last_name' => 'Student']);
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $this->createHostelBed($room, ['code' => 'FINDABLE-BED']);

        $this->get('/app/hostel-residency/search/students?q=Findable')->assertForbidden();
        $this->get('/app/hostel-residency/search/beds?q=FINDABLE')->assertForbidden();
    }

    #[Test]
    public function a_residency_manage_member_can_use_the_assign_search_endpoints(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createStudent($school, ['status' => 'active', 'first_name' => 'Findable', 'last_name' => 'Student']);
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $this->createHostelBed($room, ['code' => 'FINDABLE-BED']);

        $this->get('/app/hostel-residency/search/students?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->get('/app/hostel-residency/search/beds?q=FINDABLE')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_wrong_school_hostel_id_is_not_found_via_the_ui(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $schoolA);
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);

        $this->get("/app/hostels/{$hostelB->id}")->assertNotFound();
    }

    #[Test]
    public function a_wrong_school_residency_assignment_id_is_not_found_via_the_ui(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $schoolA);
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);
        $bedB = $this->createHostelBed($roomB);
        $studentB = $this->createStudent($schoolB, ['status' => 'active']);
        $assignmentB = $this->createHostelResidencyAssignment($studentB, $bedB);

        $this->post("/app/hostel-residency/{$assignmentB->id}/end")->assertNotFound();
    }
}
