<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED HTTP contract proof for the Profile API
 * (`GET /api/v1/schools/{school}/employees/{employee}`), checkpoint
 * brief section 73, including the section 41 lifecycle-read-consistency
 * proof folded in here since it is fundamentally a Profile-endpoint
 * concern.
 */
class HrEmployeeProfileApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")->assertUnauthorized();
    }

    #[Test]
    public function an_actor_without_any_profile_permission_is_denied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $member = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($member))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertForbidden();
    }

    #[Test]
    public function a_cross_school_employee_is_a_safe_not_found(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $employeeB = $this->createEmployee($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_nonexistent_employee_is_also_a_safe_not_found(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/".Str::uuid())
            ->assertNotFound();
    }

    #[Test]
    public function the_profile_root_has_exactly_the_accepted_top_level_keys(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk();

        $this->assertSame([
            'summary', 'personal_details', 'contact', 'addresses', 'emergency_contacts',
            'employment_history', 'assignments', 'qualifications', 'experience', 'certifications', 'documents',
            // Phase 8A closure correction: `notes` added as the profile
            // workspace's twelfth section, gated by hr.employees.notes.view.
            'notes',
        ], array_keys($response->json('data')));
    }

    #[Test]
    public function the_summary_and_employment_history_sections_have_exactly_the_accepted_keys(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'employee_id', 'employee_number', 'display_name', 'employee_record_status', 'user_linked',
            'current_employment_status', 'position_id', 'position_name', 'department_id', 'department_name',
            'campus_id', 'campus_name', 'manager_employee_id', 'manager_employee_number', 'manager_display_name',
        ], array_keys($data['summary']));

        $this->assertSame([
            'id', 'employment_type', 'starts_on', 'ends_on', 'probation_ends_on', 'status', 'is_current',
        ], array_keys($data['employment_history'][0]));
    }

    #[Test]
    public function only_the_personal_capability_shows_personal_data_but_not_assignments_or_documents(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $setupActor = $this->fullHrActor($school);
        $this->createEmployeePersonalDetail($employee, ['personal_email' => 'section-test@example.com']);
        $position = $this->createPosition($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $setupActor);
        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $setupActor);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted']);

        $limitedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($limitedActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertNotNull($data['contact']);
        $this->assertSame('section-test@example.com', $data['contact']['personal_email']);
        $this->assertSame([], $data['assignments'], 'Assignment history requires hr.employees.assignments.view, not granted here.');
        $this->assertSame([], $data['documents'], 'Documents require hr.employees.documents.view, not granted here.');
    }

    #[Test]
    public function granting_the_assignments_capability_reveals_only_the_assignments_section(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $setupActor = $this->fullHrActor($school);
        $position = $this->createPosition($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $setupActor);
        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $setupActor);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted']);

        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.assignments.view']);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertNotCount(0, $data['assignments']);
        $this->assertSame([], $data['documents'], 'Documents still require hr.employees.documents.view, not granted here.');
    }

    #[Test]
    public function an_employee_with_no_linked_user_is_supported(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['summary']['user_linked']);
    }

    #[Test]
    public function separation_and_rehire_history_are_both_correctly_represented(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        $first = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmployeeLifecycleService::class)->separate($first, '2022-12-31', $actor);
        $second = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2023-01-01'], $actor);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['employment_history']);
        $firstEntry = collect($data['employment_history'])->firstWhere('id', $first->id);
        $secondEntry = collect($data['employment_history'])->firstWhere('id', $second->id);
        $this->assertFalse($firstEntry['is_current']);
        $this->assertSame('separated', $firstEntry['status']);
        $this->assertTrue($secondEntry['is_current']);
        $this->assertSame('active', $secondEntry['status']);
        $this->assertSame('active', $data['summary']['current_employment_status']);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_future_dated_employment_is_not_current(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-09-01'], $actor);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['employment_history'][0]['is_current']);
        $this->assertNull($data['summary']['current_employment_status']);

        Carbon::setTestNow();
    }

    #[Test]
    public function no_highly_sensitive_document_ever_appears_in_the_general_profile(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive', 'category' => 'sentinel-hs-category']);
        $actor = $this->fullHrActor($school);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('highly_sensitive', $raw);
        $this->assertStringNotContainsString('sentinel-hs-category', $raw);
    }

    #[Test]
    public function no_raw_eloquent_or_storage_fields_ever_appear(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted', 'storage_path' => 'sentinel/storage/path.pdf', 'storage_disk' => 'sentinel-disk']);
        $actor = $this->fullHrActor($school);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->getContent();

        foreach (['school_id', 'created_at', 'updated_at', 'storage_path', 'storage_disk', 'sentinel/storage/path.pdf', 'sentinel-disk'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "Forbidden field/value '{$forbidden}' leaked into the Profile API response.");
        }
    }
}
