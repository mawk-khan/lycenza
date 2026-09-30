<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.1 (ADR 0063 sections 6, 23): the HTTP transport for the explicit
 * Employee<->User link lifecycle. PATCH no longer carries `user_id`;
 * POST .../link-user and POST .../unlink-user do, under
 * hr.employees.manage, with the standard error envelope and the same
 * tenant-safe 404 for a cross-School or unknown Employee.
 */
class HrEmployeeUserLinkApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function as(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function link(User $actor, School $school, string $employeeId, array $body): TestResponse
    {
        return $this->as($actor)->postJson("/api/v1/schools/{$school->id}/employees/{$employeeId}/link-user", $body);
    }

    private function unlink(User $actor, School $school, string $employeeId): TestResponse
    {
        return $this->as($actor)->postJson("/api/v1/schools/{$school->id}/employees/{$employeeId}/unlink-user");
    }

    private function linkedUserId(School $school, Employee $employee): ?string
    {
        return app(TenantContext::class)->withSchool($school, fn () => $employee->fresh()->user_id);
    }

    private function member(School $school, string $status = 'active'): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $school, $status);

        return $user;
    }

    #[Test]
    public function an_hr_manager_links_and_unlinks_through_the_explicit_endpoints(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $target = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $this->link($actor, $school, $employee->id, ['user_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.userId', $target->id);
        $this->flushHeaders();

        $this->unlink($actor, $school, $employee->id)
            ->assertOk()
            ->assertJsonPath('data.userId', null);
        $this->assertNull($this->linkedUserId($school, $employee));
    }

    #[Test]
    public function the_patch_update_rejects_user_id_and_changes_nothing(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school, ['user_id' => null, 'full_name' => 'Original']);

        $this->as($actor)
            ->patchJson("/api/v1/schools/{$school->id}/employees/{$employee->id}", ['full_name' => 'Changed', 'user_id' => $this->member($school)->id])
            ->assertUnprocessable()
            ->assertJsonPath('error.status', 422)
            ->assertJsonStructure(['error' => ['errors' => ['user_id']]]);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $employee->fresh());
        $this->assertNull($fresh->user_id);
        $this->assertSame('Original', $fresh->full_name);
    }

    #[Test]
    public function an_ineligible_user_gets_one_non_enumerating_422_whatever_the_reason(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $disabled = $this->member($school);
        $disabled->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $candidates = [
            $this->member($school, 'invited'),
            $this->member($school, 'suspended'),
            $disabled,
            $this->member($this->createSchool()),
            $this->createUser(),
        ];

        $bodies = [];
        foreach ($candidates as $candidate) {
            $employee = $this->createEmployee($school, ['user_id' => null]);
            $response = $this->link($actor, $school, $employee->id, ['user_id' => $candidate->id])->assertUnprocessable();
            $this->flushHeaders();
            $bodies[] = [$response->json('error.code'), $response->json('error.message')];
            $this->assertNull($this->linkedUserId($school, $employee));
        }

        $this->assertCount(1, array_unique(array_map('serialize', $bodies)));
        $this->assertSame('HR_UNRELATED_USER_LINKAGE', $bodies[0][0]);

        // An unknown User id answers exactly the same way.
        $employee = $this->createEmployee($school, ['user_id' => null]);
        $unknown = $this->link($actor, $school, $employee->id, ['user_id' => (string) Str::uuid7()])->assertUnprocessable();
        $this->assertSame($bodies[0], [$unknown->json('error.code'), $unknown->json('error.message')]);
    }

    #[Test]
    public function state_conflicts_answer_409_with_stable_codes(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $linkedUser = $this->member($school);
        $linked = $this->createEmployee($school, ['user_id' => $linkedUser->id]);
        $unlinked = $this->createEmployee($school, ['user_id' => null]);

        $this->link($actor, $school, $linked->id, ['user_id' => $this->member($school)->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'HR_EMPLOYEE_ALREADY_LINKED');
        $this->flushHeaders();
        $this->unlink($actor, $school, $unlinked->id)
            ->assertStatus(409)->assertJsonPath('error.code', 'HR_EMPLOYEE_NOT_LINKED');
        $this->flushHeaders();
        $this->link($actor, $school, $unlinked->id, ['user_id' => $linkedUser->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'HR_USER_ALREADY_LINKED');
    }

    #[Test]
    public function the_link_endpoints_require_hr_employees_manage(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.employees.personal.view']);
        $target = $this->member($school);
        $unlinked = $this->createEmployee($school, ['user_id' => null]);
        $linked = $this->createEmployee($school, ['user_id' => $this->member($school)->id]);

        $this->link($viewer, $school, $unlinked->id, ['user_id' => $target->id])->assertForbidden();
        $this->flushHeaders();
        $this->unlink($viewer, $school, $linked->id)->assertForbidden();

        $this->assertNull($this->linkedUserId($school, $unlinked));
        $this->assertNotNull($this->linkedUserId($school, $linked));
    }

    #[Test]
    public function an_unauthenticated_request_is_refused(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $this->postJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/link-user", ['user_id' => (string) Str::uuid7()])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_cross_school_or_unknown_employee_is_the_same_404(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $target = $this->member($schoolA);
        $employeeB = $this->createEmployee($schoolB, ['user_id' => null]);

        $this->link($actorA, $schoolA, $employeeB->id, ['user_id' => $target->id])->assertNotFound();
        $this->flushHeaders();
        $this->link($actorA, $schoolA, (string) Str::uuid7(), ['user_id' => $target->id])->assertNotFound();
        $this->flushHeaders();
        $this->unlink($actorA, $schoolA, 'not-a-uuid')->assertNotFound();

        $this->assertNull($this->linkedUserId($schoolB, $employeeB));
    }

    #[Test]
    public function the_link_body_requires_a_uuid_user_id(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $this->link($actor, $school, $employee->id, [])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['user_id']]]);
        $this->flushHeaders();
        $this->link($actor, $school, $employee->id, ['user_id' => 'someone@example.test'])->assertUnprocessable();
    }

    #[Test]
    public function creating_an_employee_with_a_user_id_still_links_through_the_hardened_rules(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->as($actor)->postJson("/api/v1/schools/{$school->id}/employees", ['full_name' => 'Linked', 'user_id' => $this->member($school)->id])
            ->assertCreated()->assertJsonPath('data.fullName', 'Linked');
        $this->flushHeaders();
        $this->as($actor)->postJson("/api/v1/schools/{$school->id}/employees", ['full_name' => 'Suspended', 'user_id' => $this->member($school, 'suspended')->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'HR_UNRELATED_USER_LINKAGE');
    }

    #[Test]
    public function employment_status_is_limited_to_the_closed_catalogue(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $this->as($actor)->postJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/employment-records", [
            'employment_type' => 'permanent', 'starts_on' => '2026-01-01', 'status' => 'employed',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['status']]]);
        $this->flushHeaders();
        $this->as($actor)->postJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/employment-records", [
            'employment_type' => 'permanent', 'starts_on' => '2026-01-01', 'status' => 'notice_period',
        ])->assertCreated();
    }

    #[Test]
    public function both_link_operations_are_live_gated_and_documented_in_the_contract(): void
    {
        $yaml = (string) file_get_contents(base_path('../../packages/contracts/openapi/school-os-api.yaml'));

        foreach (['link-user' => 'linkEmployeeUser', 'unlink-user' => 'unlinkEmployeeUser'] as $segment => $operationId) {
            $route = Route::getRoutes()->match(Request::create('/api/v1/schools/'.Str::uuid().'/employees/'.Str::uuid()."/{$segment}", 'POST'));
            $this->assertContains('capability:hr.employees.manage', $route->gatherMiddleware());
            $this->assertContains('idempotent', $route->gatherMiddleware());

            $this->assertMatchesRegularExpression("#\n  /schools/\\{schoolId\\}/employees/\\{employeeId\\}/{$segment}:\n    post:\n      operationId: {$operationId}\n#", $yaml);
        }

        $this->assertStringContainsString("\n    EmployeeCoreRecord:\n", $yaml);
    }
}
