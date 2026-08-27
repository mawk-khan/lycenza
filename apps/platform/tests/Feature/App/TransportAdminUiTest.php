<?php

namespace Tests\Feature\App;

use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10B -- the administrative Transport Inertia UI
 * (App\Http\Controllers\App\{TransportRouteController,
 * TransportVehicleController, TransportOperationsController,
 * TransportStudentAssignmentController}). Backend authorization/
 * tenant-safety/domain invariants are already proven by the JSON API
 * test suite (TransportApiTest, TransportRouteLifecycleTest,
 * TransportVehicleLifecycleTest) -- these tests cover the
 * Inertia-specific integration: page rendering, capability-aware
 * props, and -- the checkpoint brief's explicit mandate -- that the
 * live search/lookup endpoints powering the assignment forms reject an
 * unauthorized School member exactly like the mutation itself, never
 * repeating the Phase 10A Library P1 finding
 * (LibraryAdminUiTest::a_member_without_any_library_capability_cannot_use_the_checkout_search_endpoints).
 */
class TransportAdminUiTest extends TestCase
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

    // --- Routes -------------------------------------------------------------

    #[Test]
    public function a_member_with_routes_view_sees_the_routes_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $this->createTransportRoute($school, ['name' => 'Morning Loop']);

        $this->get('/app/transport/routes')->assertInertia(fn ($page) => $page
            ->component('App/Transport/Routes/Index')
            ->where('canManage', true)
            ->has('routes.data', 1)
        );
    }

    #[Test]
    public function a_member_without_routes_view_is_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/transport/routes')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_register_a_route_and_a_stop_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/transport/routes', ['code' => 'UI-R1', 'name' => 'UI Route']);
        $response->assertRedirect();
        $route = app(TenantContext::class)->withSchool($school, fn () => TransportRoute::query()->where('code', 'UI-R1')->first());
        $this->assertNotNull($route);

        $stopResponse = $this->post("/app/transport/routes/{$route->id}/stops", ['name' => 'Main Gate', 'sequence' => 1]);
        $stopResponse->assertRedirect();

        $this->get("/app/transport/routes/{$route->id}")->assertInertia(fn ($page) => $page
            ->component('App/Transport/Routes/Show')
            ->has('stops', 1)
            ->where('stops.0.name', 'Main Gate')
        );
    }

    #[Test]
    public function a_view_only_routes_member_cannot_register_a_route(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $this->grantOnly($school, $viewer, 'test_routes_viewer_ui', 'transport.routes.view');
        $this->activate($viewer, $school);

        $this->post('/app/transport/routes', ['code' => 'DENIED', 'name' => 'Denied'])->assertForbidden();
        $exists = app(TenantContext::class)->withSchool($school, fn () => TransportRoute::query()->where('code', 'DENIED')->exists());
        $this->assertFalse($exists);
    }

    // --- Vehicles -------------------------------------------------------------

    #[Test]
    public function a_member_with_vehicles_view_sees_the_vehicles_index(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createTransportVehicle($school, ['code' => 'BUS-1']);

        $this->get('/app/transport/vehicles')->assertInertia(fn ($page) => $page
            ->component('App/Transport/Vehicles/Index')
            ->where('canManage', true)
            ->has('vehicles.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_register_a_vehicle_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/transport/vehicles', ['code' => 'UI-BUS-1', 'registration_number' => 'UI-REG-1']);

        $response->assertRedirect();
        $created = app(TenantContext::class)->withSchool($school, fn () => TransportVehicle::query()->where('code', 'UI-BUS-1')->first());
        $this->assertNotNull($created);
    }

    // --- Route operations -------------------------------------------------------

    #[Test]
    public function a_member_with_vehicles_view_sees_the_operations_index(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createTransportRoute($school);

        $this->get('/app/transport/operations')->assertInertia(fn ($page) => $page
            ->component('App/Transport/Operations/Index')
            ->has('routes.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_assign_and_end_a_vehicle_driver_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);

        $assign = $this->post("/app/transport/operations/{$route->id}", [
            'vehicle_id' => $vehicle->id, 'driver_employee_id' => $driver->id,
        ]);
        $assign->assertRedirect("/app/transport/operations/{$route->id}");

        $current = app(TenantContext::class)->withSchool($school, fn () => $route->refresh()->activeRouteAssignment()->first());
        $this->assertNotNull($current);

        $end = $this->post("/app/transport/route-assignments/{$current->id}/end");
        $end->assertRedirect("/app/transport/operations/{$route->id}");
        $ended = app(TenantContext::class)->withSchool($school, fn () => $current->fresh());
        $this->assertSame('ended', $ended->status);
    }

    /**
     * THE anti-P1 regression (checkpoint brief, explicitly citing the
     * Phase 10A Library finding): the operational-assignment form's
     * live search endpoints must enforce transport.vehicles.manage
     * exactly like the assign action itself -- an unauthenticated-
     * capability member must never be able to enumerate Vehicle
     * registration numbers or Employee names/numbers through these
     * endpoints.
     */
    #[Test]
    public function a_member_without_vehicles_manage_cannot_use_the_operations_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);
        $this->createTransportVehicle($school, ['registration_number' => 'FINDABLE-REG']);
        $this->createEmployee($school, ['full_name' => 'Findable Driver']);

        $this->get('/app/transport/operations/search/vehicles?q=FINDABLE')->assertForbidden();
        $this->get('/app/transport/operations/search/drivers?q=Findable')->assertForbidden();
    }

    #[Test]
    public function a_vehicles_manage_member_can_use_the_operations_search_endpoints(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createTransportVehicle($school, ['registration_number' => 'FINDABLE-REG']);
        $this->createEmployee($school, ['full_name' => 'Findable Driver']);

        $this->get('/app/transport/operations/search/vehicles?q=FINDABLE')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->get('/app/transport/operations/search/drivers?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // --- Student assignments -------------------------------------------------------

    #[Test]
    public function a_member_with_assignments_view_sees_the_assignments_index(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);
        $this->createTransportStudentAssignment($student, $route);

        $this->get('/app/transport/assignments')->assertInertia(fn ($page) => $page
            ->component('App/Transport/Assignments/Index')
            ->where('canManage', true)
            ->has('assignments.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_assign_and_end_a_student_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $assign = $this->post('/app/transport/assignments', ['student_id' => $student->id, 'route_id' => $route->id]);
        $assign->assertRedirect('/app/transport/assignments');

        $created = app(TenantContext::class)->withSchool($school, fn () => TransportStudentAssignment::query()->where('student_id', $student->id)->first());
        $this->assertNotNull($created);
        $this->assertSame('active', $created->status);

        $end = $this->post("/app/transport/assignments/{$created->id}/end");
        $end->assertRedirect('/app/transport/assignments');
        $ended = app(TenantContext::class)->withSchool($school, fn () => $created->fresh());
        $this->assertSame('ended', $ended->status);
    }

    #[Test]
    public function assigning_an_already_assigned_student_via_the_ui_shows_a_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $routeOne = $this->createTransportRoute($school);
        $routeTwo = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->post('/app/transport/assignments', ['student_id' => $student->id, 'route_id' => $routeOne->id])->assertRedirect();

        $second = $this->post('/app/transport/assignments', ['student_id' => $student->id, 'route_id' => $routeTwo->id]);
        $second->assertSessionHasErrors('student_id');
    }

    /**
     * THE anti-P1 regression, applied to the Student assignment form.
     */
    #[Test]
    public function a_member_without_assignments_manage_cannot_use_the_assignment_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);
        $route = $this->createTransportRoute($school);
        $this->createTransportStop($route);
        $this->createStudent($school, ['status' => 'active', 'first_name' => 'Findable']);

        $this->get('/app/transport/assignments/search/students?q=Findable')->assertForbidden();
        $this->get('/app/transport/assignments/search/routes?q=a')->assertForbidden();
        $this->get("/app/transport/assignments/routes/{$route->id}/stops")->assertForbidden();
    }

    #[Test]
    public function an_assignments_manage_member_can_use_the_assignment_search_endpoints(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $route = $this->createTransportRoute($school);
        $this->createTransportStop($route, ['name' => 'Gate 1']);
        $this->createStudent($school, ['status' => 'active', 'first_name' => 'Findable']);

        $this->get('/app/transport/assignments/search/students?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->get('/app/transport/assignments/search/routes?q='.$route->code)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->get("/app/transport/assignments/routes/{$route->id}/stops")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
