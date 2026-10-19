<?php

namespace Tests\Feature\Canteen;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
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
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-3 D3-F (ADR 0076 §39): Canteen orders are Campus-adopted by the
 * Outlet's Campus. A Campus-scoped operator places orders only at their
 * Campuses' Outlets for current operational Students of that Campus
 * (re-checked when placed), sees and works only those orders, and fulfils
 * them with no Inventory or Fees authority; School-wide Outlets, the
 * directory, settings and Student profiles stay whole-School.
 */
class CanteenCampusScopeTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private CanteenItem $item;

    private CanteenOutlet $shared;

    /** @var array<string, array{outlet: CanteenOutlet, section: Section}> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $year = $this->createAcademicYear($this->school, ['code' => 'C2627', 'name' => '2026-27', 'status' => 'active']);
        $grade = $this->createGradeLevel($this->school);
        $this->configureCanteenBilling($this->school, $this->createLedgerAccount($this->school, ['type' => 'asset']), $this->createLedgerAccount($this->school, ['type' => 'income']));
        $this->item = $this->createCanteenItem($this->school, ['code' => 'MEAL', 'price' => '10.00', 'status' => 'active']);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $location = $this->createInventoryLocation($this->school, ['campus_id' => $campus->id, 'status' => 'active']);
            $outlet = $this->createCanteenOutlet($this->school, $location, ['campus_id' => $campus->id, 'code' => 'OUT'.strtoupper($key), 'status' => 'active']);
            $section = $this->createSection($year, $campus, $grade, ['code' => 'S'.strtoupper($key), 'status' => 'active']);
            $this->at[$key] = compact('outlet', 'section');
        }
        $this->shared = $this->createCanteenOutlet($this->school, $this->createInventoryLocation($this->school, ['status' => 'active']), ['campus_id' => null, 'code' => 'SHARED', 'status' => 'active']);
    }

    private function pupil(string $name, ?string $campus): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Pupil', 'status' => 'active']);
        if ($campus !== null) {
            $this->createStudentEnrollment($student, $this->at[$campus]['section'], ['status' => 'active']);
        }

        return $student;
    }

    /** @param  list<?string>  $campusIds */
    private function operator(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'canteen_operator', $campusId);
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

    private function place(User $user, string $studentId, CanteenOutlet $outlet, string $key): TestResponse
    {
        return $this->api($user)->withHeader('Idempotency-Key', $key)->postJson($this->url('/canteen-orders'), [
            'student_id' => $studentId, 'outlet_id' => $outlet->id, 'lines' => [['canteen_item_id' => $this->item->id, 'quantity' => 1]],
        ]);
    }

    private function orders(): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => CanteenOrder::query()->count());
    }

    /** The error envelope without its request id, with the caller's own id normalized. */
    private function error(TestResponse $response, string $id): string
    {
        return str_replace($id, '{id}', (string) json_encode(array_diff_key((array) $response->json('error'), ['requestId' => true])));
    }

    #[Test]
    public function a_whole_school_operator_is_unchanged(): void
    {
        $user = $this->operator([null]);
        $this->pupil('Unplaced', null);
        $this->api($user)->getJson($this->url('/canteen-orders/search/students?q=Unplaced'))->assertOk()->assertJsonCount(1, 'data');
        $this->place($user, $this->pupil('Cross', 'b')->id, $this->at['a']['outlet'], 'canteen-whole-cross')->assertCreated();
        $this->place($user, $this->pupil('Shared', 'a')->id, $this->shared, 'canteen-whole-shared')->assertCreated();
    }

    #[Test]
    public function a_campus_a_operator_works_only_campus_a_outlets_and_students(): void
    {
        $user = $this->operator([$this->a->id]);
        $here = $this->pupil('Here', 'a');
        $there = $this->pupil('There', 'b');
        $bOrder = $this->createCanteenOrderRaw($this->school, $there, $this->at['b']['outlet']);
        $sharedOrder = $this->createCanteenOrderRaw($this->school, $here, $this->shared);

        // Picker and form: Campus A only, minimal identity.
        $rows = $this->api($user)->getJson($this->url('/canteen-orders/search/students?q=Pupil'))->assertOk()->json('data');
        $this->assertSame([['id' => $here->id, 'studentNumber' => $here->student_number, 'name' => 'Here Pupil']], $rows);
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/canteen-orders/create')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('outlets', [['id' => $this->at['a']['outlet']->id, 'code' => 'OUTA', 'name' => $this->at['a']['outlet']->name]]));

        // Lists and direct access: Campus B's and the School-wide Outlet's orders are NOT FOUND.
        $this->assertSame([], $this->api($user)->getJson($this->url('/canteen-orders'))->assertOk()->json('data'));
        foreach ([$bOrder, $sharedOrder] as $order) {
            $this->api($user)->getJson($this->url("/canteen-orders/{$order->id}"))->assertNotFound();
            $this->api($user)->withHeader('Idempotency-Key', 'canteen-fulfil-'.$order->id)->postJson($this->url("/canteen-orders/{$order->id}/fulfill"))->assertNotFound();
            $this->api($user)->postJson($this->url("/canteen-orders/{$order->id}/cancel"))->assertNotFound();
        }

        // Placing: another Campus's or the School-wide Outlet is NOT FOUND; another Campus's
        // Student answers exactly like an unknown one.
        $before = $this->orders();
        $this->place($user, $here->id, $this->at['b']['outlet'], 'canteen-b-outlet')->assertNotFound()->assertJsonPath('error.code', 'CANTEEN_OUTLET_NOT_FOUND');
        $this->place($user, $here->id, $this->shared, 'canteen-shared-outlet')->assertNotFound()->assertJsonPath('error.code', 'CANTEEN_OUTLET_NOT_FOUND');
        $unknown = (string) Str::uuid();
        $this->assertSame(
            $this->error($this->place($user, $unknown, $this->at['a']['outlet'], 'canteen-unknown')->assertNotFound(), $unknown),
            $this->error($this->place($user, $there->id, $this->at['a']['outlet'], 'canteen-b-student')->assertNotFound(), $there->id),
        );
        $this->assertSame($before, $this->orders());

        // Allowed: a current Campus A Student at the Campus A Outlet, then fulfilment --
        // stock and charge as Canteen's own side effect, with no Fees or Inventory authority.
        $orderId = $this->place($user, $here->id, $this->at['a']['outlet'], 'canteen-ok')->assertCreated()->json('data.id');
        $this->api($user)->withHeader('Idempotency-Key', 'canteen-fulfil-ok')->postJson($this->url("/canteen-orders/{$orderId}/fulfill"))->assertOk()->assertJsonPath('data.status', 'fulfilled');
        foreach (['fees.charges.view', 'finance.payments.record', 'inventory.stock.manage'] as $capability) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$capability, $this->school]), $capability);
        }
    }

    #[Test]
    public function placing_re_checks_the_students_current_campus_and_state(): void
    {
        $user = $this->operator([$this->a->id]);
        $mover = $this->pupil('Mover', 'a');
        $leaver = $this->pupil('Leaver', 'a');
        $this->assertCount(2, $this->api($user)->getJson($this->url('/canteen-orders/search/students?q=Pupil'))->json('data'));

        app(TenantContext::class)->withSchool($this->school, function () use ($mover, $leaver): void {
            StudentEnrollment::query()->where('student_id', $mover->id)->update(['status' => 'transferred', 'ends_on' => now()->toDateString()]);
            $leaver->forceFill(['status' => 'inactive'])->save();
        });
        $this->createStudentEnrollment($mover, $this->at['b']['section'], ['status' => 'active']);

        $this->place($user, $mover->id, $this->at['a']['outlet'], 'canteen-mover')->assertNotFound()->assertJsonPath('error.code', 'CANTEEN_STUDENT_NOT_FOUND');
        $this->place($user, $leaver->id, $this->at['a']['outlet'], 'canteen-leaver')->assertNotFound()->assertJsonPath('error.code', 'CANTEEN_STUDENT_NOT_FOUND');
        $this->assertSame(0, $this->orders());
    }

    #[Test]
    public function configuration_and_student_profiles_stay_whole_school_and_the_module_gates(): void
    {
        $user = $this->operator([$this->a->id]);
        $student = $this->pupil('Here', 'a');
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/canteen-outlets')->assertForbidden();
        $this->get('/app/canteen-items')->assertForbidden();
        $this->get("/app/students/{$student->id}")->assertForbidden();
        $this->getJson('/app/canteen-orders/search/items?q=MEAL')->assertOk()->assertJsonCount(1, 'data');

        SchoolFactory::setOptionalModules($this->school, false, ['canteen']);
        $this->api($user)->getJson($this->url('/canteen-orders'))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['canteen']);
        $this->api($user)->getJson($this->url('/canteen-orders'))->assertOk();

        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url('/canteen-orders'))->assertForbidden();
    }
}
