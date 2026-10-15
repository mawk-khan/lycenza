<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeNumberConflictException;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C1:
 * - UAT-HR-20: the demo seeded EMP-000001..012 without the allocator, so the
 *   first Employee added in the browser collided with EMP-000001 and failed
 *   with a 500 on every attempt. The demo now numbers through the allocator.
 * - UAT-HR-20b: a number collision (the School's counter behind numbers
 *   written some other way) is a plain refusal, not a 500, and the database
 *   error is still reported for operators.
 */
class EmployeeNumberingAfterDemoSeedTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function in(School $school, \Closure $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    /** Builds the demo and adds one Employee through the browser route as the HR persona. */
    private function addEmployeeAfterDemoBuild(): array
    {
        $school = app(DemoDataBuilder::class)->build()->school;
        $hr = User::query()->where('email', 'hr.payroll@example.test')->firstOrFail();
        $this->actingAs($hr)->post("/app/schools/{$school->id}/activate");

        $seeded = $this->in($school, fn () => Employee::query()->orderBy('employee_number')->pluck('employee_number')->all());

        $response = $this->post('/app/hr/employees', ['full_name' => 'Pooja Sharma', 'work_email' => 'pooja.sharma@example.test']);
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasNoErrors()->assertRedirect();

        $created = $this->in($school, fn () => Employee::query()->where('full_name', 'Pooja Sharma')->firstOrFail());

        return [$seeded, $created->employee_number];
    }

    #[Test]
    public function after_a_demo_build_the_next_employee_gets_the_next_free_number(): void
    {
        [$seeded, $created] = $this->addEmployeeAfterDemoBuild();

        $this->assertSame(array_map(fn (int $n) => sprintf('EMP-%06d', $n), range(1, 12)), $seeded);
        $this->assertSame('EMP-000013', $created);
    }

    #[Test]
    public function a_second_demo_build_starts_numbering_from_the_same_place(): void
    {
        // Each test starts from an empty database, as a demo reset does: the
        // same build must hand out the same next number every time.
        [, $created] = $this->addEmployeeAfterDemoBuild();

        $this->assertSame('EMP-000013', $created);
    }

    #[Test]
    public function a_number_collision_is_a_refusal_that_creates_nothing_and_is_still_reported(): void
    {
        Exceptions::fake();
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        // Reproduce the out-of-step state: a row written without the allocator.
        $this->in($school, fn () => Employee::query()->create([
            'school_id' => $school->id, 'user_id' => null, 'employee_number' => 'EMP-000001',
            'full_name' => 'Written out of band', 'record_status' => 'active',
        ]));

        try {
            app(EmployeeService::class)->create($school, ['full_name' => 'Next Hire'], $user);
            $this->fail('A number collision must be refused.');
        } catch (EmployeeNumberConflictException $e) {
            $this->assertSame('HR_EMPLOYEE_NUMBER_CONFLICT', $e->errorCode());
            $this->assertStringContainsString('EMP-000001', $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
            $this->assertStringNotContainsString('employees_school_id_employee_number_unique', $e->getMessage());
        }

        $this->assertSame(0, $this->in($school, fn () => Employee::query()->where('full_name', 'Next Hire')->count()));
        Exceptions::assertReported(UniqueConstraintViolationException::class);
    }

    #[Test]
    public function the_browser_form_shows_a_number_collision_as_a_form_error_not_a_500(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $this->in($school, fn () => Employee::query()->create([
            'school_id' => $school->id, 'user_id' => null, 'employee_number' => 'EMP-000001',
            'full_name' => 'Written out of band', 'record_status' => 'active',
        ]));

        $response = $this->post('/app/hr/employees', ['full_name' => 'Next Hire']);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors('employee');
        $this->assertStringNotContainsString('SQLSTATE', session('errors')->first('employee'));
    }

    #[Test]
    public function the_api_returns_a_409_for_a_number_collision(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->in($school, fn () => Employee::query()->create([
            'school_id' => $school->id, 'user_id' => null, 'employee_number' => 'EMP-000001',
            'full_name' => 'Written out of band', 'record_status' => 'active',
        ]));

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('Idempotency-Key', 'test-collision')
            ->postJson("/api/v1/schools/{$school->id}/employees", ['full_name' => 'Next Hire'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HR_EMPLOYEE_NUMBER_CONFLICT');
    }

    #[Test]
    public function a_duplicate_work_email_is_still_reported_on_its_own_field(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $this->post('/app/hr/employees', ['full_name' => 'First', 'work_email' => 'same@example.test'])->assertSessionHasNoErrors();

        $this->post('/app/hr/employees', ['full_name' => 'Second', 'work_email' => 'same@example.test'])
            ->assertSessionHasErrors('work_email');
    }

    #[Test]
    public function creating_an_employee_still_requires_the_manage_capability(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $viewer = $this->createUserWithCapabilities($school, ['hr.employees.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");

        $this->post('/app/hr/employees', ['full_name' => 'Not Allowed'])->assertForbidden();
        $this->assertSame(0, $this->in($school, fn () => Employee::query()->where('full_name', 'Not Allowed')->count()));
    }
}
