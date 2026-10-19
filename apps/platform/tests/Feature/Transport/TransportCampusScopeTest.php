<?php

namespace Tests\Feature\Transport;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-5 (ADR 0076 §41): Transport route viewing and rider assignments are
 * Campus-adopted by the route's Campus. A Campus-scoped actor sees and works
 * only Campus-bound routes in scope, places only current operational Students
 * of the route's Campus, and never touches a School-shared (NULL) route; route
 * and stop management, the fleet and Transport fees stay whole-School, and a
 * route's Campus is fixed once it is used.
 */
class TransportCampusScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    /** @var array<string, array{route: TransportRoute, stop: TransportStop, section: Section}> */
    private array $at = [];

    private TransportRoute $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $year = $this->createAcademicYear($this->school, ['code' => 'T2627', 'name' => '2026-27', 'status' => 'active']);
        $grade = $this->createGradeLevel($this->school);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $route = $this->createTransportRoute($this->school, ['campus_id' => $campus->id, 'code' => 'RT-'.strtoupper($key), 'name' => 'Route '.strtoupper($key), 'status' => 'active']);
            $stop = $this->createTransportStop($route, ['name' => 'Stop '.strtoupper($key), 'sequence' => 1, 'status' => 'active']);
            $section = $this->createSection($year, $campus, $grade, ['code' => 'S'.strtoupper($key), 'status' => 'active']);
            $this->at[$key] = compact('route', 'stop', 'section');
        }
        $this->shared = $this->createTransportRoute($this->school, ['campus_id' => null, 'code' => 'RT-SHARED', 'name' => 'Shared', 'status' => 'active']);
    }

    private function pupil(string $name, ?string $campus): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Rider', 'status' => 'active']);
        if ($campus !== null) {
            $this->createStudentEnrollment($student, $this->at[$campus]['section'], ['status' => 'active']);
        }

        return $student;
    }

    /** @param  list<?string>  $campusIds */
    private function coordinator(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'transport_coordinator', $campusId);
        }

        return $user;
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path): string
    {
        return "/api/v1/schools/{$this->school->id}{$path}";
    }

    private function assign(User $user, string $studentId, TransportRoute $route, string $key, ?string $stopId = null): TestResponse
    {
        return $this->api($user)->withHeader('Idempotency-Key', $key)->postJson($this->url('/transport-student-assignments'), [
            'student_id' => $studentId, 'route_id' => $route->id, 'pickup_stop_id' => $stopId,
        ]);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function riders(): int
    {
        return $this->inSchool(fn () => TransportStudentAssignment::query()->count());
    }

    #[Test]
    public function a_whole_school_coordinator_is_unchanged(): void
    {
        $user = $this->coordinator([null]);
        $this->assign($user, $this->pupil('Shared', null)->id, $this->shared, 'transport-whole-shared')->assertCreated();
        $this->assign($user, $this->pupil('Cross', 'b')->id, $this->at['a']['route'], 'transport-whole-cross')->assertCreated();
        $this->api($user)->getJson($this->url('/transport-routes'))->assertOk()->assertJsonCount(3, 'data');
    }

    #[Test]
    public function a_campus_a_coordinator_works_only_campus_a_routes_and_students(): void
    {
        $user = $this->coordinator([$this->a->id]);
        $here = $this->pupil('Here', 'a');
        $there = $this->pupil('There', 'b');
        $bRider = $this->createTransportStudentAssignment($there, $this->at['b']['route']);
        $sharedRider = $this->createTransportStudentAssignment($this->pupil('Anyone', null), $this->shared);

        // Routes, stops and riders: Campus A only; the shared route and Campus B are NOT FOUND.
        $this->assertSame([$this->at['a']['route']->id], $this->api($user)->getJson($this->url('/transport-routes'))->assertOk()->json('data.*.id'));
        foreach ([$this->at['b']['route'], $this->shared] as $route) {
            $this->api($user)->getJson($this->url("/transport-routes/{$route->id}"))->assertNotFound();
            $this->api($user)->getJson($this->url("/transport-routes/{$route->id}/stops"))->assertNotFound();
        }
        $this->assertSame([], $this->api($user)->getJson($this->url('/transport-student-assignments'))->assertOk()->json('data'));
        $this->assertSame([], $this->api($user)->getJson($this->url('/transport-student-assignments?student_id='.$there->id))->assertOk()->json('data'));
        foreach ([$bRider, $sharedRider] as $rider) {
            $this->api($user)->getJson($this->url("/transport-student-assignments/{$rider->id}"))->assertNotFound();
            $this->api($user)->postJson($this->url("/transport-student-assignments/{$rider->id}/end"))->assertNotFound();
        }

        // Pickers: Campus A Students, Campus A routes and their stops only.
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->assertSame([['id' => $here->id, 'studentNumber' => $here->student_number, 'name' => 'Here Rider']], $this->getJson('/app/transport/assignments/search/students?q=rider')->assertOk()->json('data'));
        $this->assertSame([$this->at['a']['route']->id], array_column($this->getJson('/app/transport/assignments/search/routes?q=rt')->assertOk()->json('data'), 'id'));
        $this->getJson('/app/transport/assignments/search/routes?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/app/transport/assignments/routes/{$this->at['b']['route']->id}/stops")->assertNotFound();
        $this->get('/app/transport/routes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('routes.data', 1));
        $this->get("/app/transport/routes/{$this->at['a']['route']->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('feePanel', null)->where('canManage', false));

        // Writes: another Campus's or the shared route, or another Campus's stop, is NOT FOUND;
        // another Campus's Student answers exactly like an unknown one; nothing is written.
        $this->assign($user, $here->id, $this->at['b']['route'], 'transport-b-route')->assertNotFound();
        $this->assign($user, $here->id, $this->shared, 'transport-shared-route')->assertNotFound();
        $this->assign($user, $here->id, $this->at['a']['route'], 'transport-b-stop', $this->at['b']['stop']->id)->assertNotFound();
        $other = $this->assign($user, $there->id, $this->at['a']['route'], 'transport-b-student')->assertStatus(422)->assertJsonPath('error.code', 'TRANSPORT_ASSIGNMENT_UNAVAILABLE');
        $unknown = $this->assign($user, (string) Str::uuid(), $this->at['a']['route'], 'transport-unknown')->assertStatus(422);
        $this->assertSame(array_diff_key($other->json('error'), ['requestId' => 1]), array_diff_key($unknown->json('error'), ['requestId' => 1]));
        $this->assertSame(2, $this->riders());

        // Allowed, with its own stop; an already-assigned Student is the same generic refusal.
        $riderId = $this->assign($user, $here->id, $this->at['a']['route'], 'transport-allowed', $this->at['a']['stop']->id)->assertCreated()->json('data.id');
        $this->assertSame(array_diff_key($other->json('error'), ['requestId' => 1]), array_diff_key($this->assign($user, $here->id, $this->at['a']['route'], 'transport-again')->assertStatus(422)->json('error'), ['requestId' => 1]));
        $this->api($user)->postJson($this->url("/transport-student-assignments/{$riderId}/end"))->assertOk()->assertJsonPath('data.status', 'ended');
    }

    #[Test]
    public function route_management_the_fleet_fees_and_profiles_stay_whole_school(): void
    {
        $user = $this->coordinator([$this->a->id]);
        $student = $this->pupil('Here', 'a');
        $this->api($user)->patchJson($this->url("/transport-routes/{$this->at['a']['route']->id}"), ['name' => 'Renamed'])->assertForbidden();
        $this->api($user)->postJson($this->url("/transport-routes/{$this->at['a']['route']->id}/stops"), ['name' => 'X', 'sequence' => 9])->assertForbidden();
        $this->api($user)->getJson($this->url('/transport-vehicles'))->assertForbidden();
        $this->api($user)->getJson($this->url("/transport-routes/{$this->at['a']['route']->id}/fee-head"))->assertForbidden();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/transport/fees')->assertForbidden();
        $this->get('/app/transport/operations')->assertForbidden();
        $this->get("/app/students/{$student->id}")->assertForbidden();
        foreach (['fees.charges.view', 'finance.charges.view', 'transport.routes.manage', 'transport.vehicles.view', 'students.view'] as $capability) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$capability, $this->school]), $capability);
        }
    }

    #[Test]
    public function a_routes_campus_is_set_on_an_active_campus_and_fixed_once_used(): void
    {
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $this->school), 'school_admin');
        $fresh = $this->createTransportRoute($this->school, ['campus_id' => null, 'code' => 'RT-NEW', 'status' => 'active']);

        // Before first use the Campus may change, to an active Campus of this School only.
        $this->api($admin)->patchJson($this->url("/transport-routes/{$fresh->id}"), ['campus_id' => $this->a->id])->assertOk()->assertJsonPath('data.campusId', $this->a->id);
        $this->api($admin)->patchJson($this->url("/transport-routes/{$fresh->id}"), ['campus_id' => 'not-a-uuid'])->assertUnprocessable();
        $other = $this->createCampus($this->createSchool());
        $this->api($admin)->patchJson($this->url("/transport-routes/{$fresh->id}"), ['campus_id' => $other->id])->assertUnprocessable();
        $this->inSchool(fn () => $this->b->forceFill(['status' => 'inactive'])->save());
        $this->api($admin)->patchJson($this->url("/transport-routes/{$fresh->id}"), ['campus_id' => $this->b->id])->assertUnprocessable();
        $this->inSchool(fn () => $this->b->forceFill(['status' => 'active'])->save());

        // Once a Stop or a rider exists, it is fixed (web and API).
        foreach ([$this->at['a']['route'], $this->shared] as $i => $used) {
            if ($i === 1) {
                $this->createTransportStudentAssignment($this->pupil('Rider', null), $used);
            }
            $this->api($admin)->patchJson($this->url("/transport-routes/{$used->id}"), ['campus_id' => $this->b->id])
                ->assertUnprocessable()->assertJsonPath('error.errors.campus_id.0', 'A Route\'s Campus cannot change once it has Stops, riders or vehicle assignments.');
        }
        $this->actingAs($admin)->post("/app/schools/{$this->school->id}/activate");
        $this->from("/app/transport/routes/{$this->shared->id}")->patch("/app/transport/routes/{$this->shared->id}", ['campus_id' => $this->a->id])->assertSessionHasErrors('campus_id');
        $this->assertNull($this->inSchool(fn () => TransportRoute::query()->findOrFail($this->shared->id)->campus_id));

        // Web create sets a Campus.
        $this->post('/app/transport/routes', ['code' => 'RT-WEB', 'name' => 'Web', 'campus_id' => $this->b->id])->assertRedirect();
        $this->assertSame($this->b->id, $this->inSchool(fn () => TransportRoute::query()->where('code', 'RT-WEB')->value('campus_id')));
    }

    #[Test]
    public function module_and_campus_state_gate_campus_authority(): void
    {
        $user = $this->coordinator([$this->a->id]);
        SchoolFactory::setOptionalModules($this->school, false, ['transport']);
        $this->api($user)->getJson($this->url('/transport-routes'))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['transport']);
        $this->api($user)->getJson($this->url('/transport-routes'))->assertOk();

        $this->inSchool(fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url('/transport-routes'))->assertForbidden();
    }

    /**
     * ORG.7D-5: the fee head and a rider's fee intent are reachable with no
     * ambient School context (as in production) -- their ids are resolved after
     * `school-membership` sets it, not by implicit route-model binding.
     */
    #[Test]
    public function the_fee_endpoints_resolve_their_records_inside_the_school_context(): void
    {
        $user = $this->coordinator([null]);
        $rider = $this->createTransportStudentAssignment($this->pupil('Payer', 'a'), $this->at['a']['route']);
        app(TenantContext::class)->clearAll();

        $this->api($user)->getJson($this->url("/transport-routes/{$this->at['a']['route']->id}/fee-head"))->assertOk();
        $this->api($user)->getJson($this->url("/transport-student-assignments/{$rider->id}/fee-selections"))->assertOk();
        $this->api($user)->getJson($this->url('/transport-routes/'.Str::uuid().'/fee-head'))->assertNotFound();
    }

    /**
     * Security review (ADR 0076 §41.9): an in-scope inactive route or a stop of
     * another in-scope route is refused the same way whatever the named
     * Student is -- the answer never reveals an out-of-scope Student's state.
     */
    #[Test]
    public function route_and_stop_refusals_never_depend_on_the_named_student(): void
    {
        $user = $this->coordinator([$this->a->id]);
        $inactive = $this->createTransportRoute($this->school, ['campus_id' => $this->a->id, 'code' => 'RT-OFF', 'status' => 'inactive']);
        $second = $this->createTransportRoute($this->school, ['campus_id' => $this->a->id, 'code' => 'RT-A2', 'status' => 'active']);
        $otherStop = $this->createTransportStop($second, ['name' => 'Elsewhere', 'sequence' => 1, 'status' => 'active']);
        $elsewhere = $this->pupil('There', 'b')->id;
        $unknown = (string) Str::uuid();
        $error = fn (TestResponse $r) => array_diff_key((array) $r->json('error'), ['requestId' => 1]);

        foreach ([[$inactive, null, 'TRANSPORT_ROUTE_NOT_AVAILABLE'], [$this->at['a']['route'], $otherStop->id, 'TRANSPORT_STOP_NOT_ON_ROUTE']] as $i => [$route, $stop, $code]) {
            $known = $this->assign($user, $elsewhere, $route, "transport-oracle-known-{$i}", $stop)->assertStatus(422)->assertJsonPath('error.code', $code);
            $this->assertSame($error($known), $error($this->assign($user, $unknown, $route, "transport-oracle-unknown-{$i}", $stop)->assertStatus(422)));
        }
        $this->assertSame(0, $this->riders());
    }
}
