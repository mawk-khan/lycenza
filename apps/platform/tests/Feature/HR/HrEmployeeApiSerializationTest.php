<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED serialization contract proof (checkpoint
 * brief section 76): stable transport value formats across the
 * Directory/Profile/Timeline APIs, mirroring the existing 8A.8/8A.9/
 * 8A.11 DTOs' own `toArray()` shapes exactly -- this file proves the
 * HTTP JSON encoding of those shapes, not the shapes themselves.
 */
class HrEmployeeApiSerializationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function employee_and_related_ids_are_plain_uuid_strings(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $entry = collect($response->json('data'))->firstWhere('employee_id', $employee->id);
        $this->assertIsString($entry['employee_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $entry['employee_id']);
    }

    #[Test]
    public function date_only_domain_fields_remain_yyyy_mm_dd(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-06-01'], $actor);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['employment_history'][0]['starts_on']);
        $this->assertNull($data['employment_history'][0]['ends_on']);
    }

    #[Test]
    public function timeline_occurred_at_is_iso_8601_with_timezone(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Serialization Timeline Test'], $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertOk();

        $occurredAt = $response->json('data.0.occurred_at');
        $this->assertNotNull($occurredAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $occurredAt);
    }

    #[Test]
    public function booleans_serialize_as_real_json_booleans_not_zero_one(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $actor);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertIsBool($data['summary']['user_linked']);
        $this->assertIsBool($data['assignments'][0]['is_primary']);
        $this->assertIsBool($data['assignments'][0]['is_current']);
    }

    #[Test]
    public function a_missing_singleton_section_is_null_never_an_empty_object(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        $this->assertNull($data['personal_details']);
        $this->assertNull($data['contact']);
    }

    #[Test]
    public function empty_repeatable_sections_are_an_empty_array_never_null(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->json('data');

        foreach (['addresses', 'emergency_contacts', 'employment_history', 'assignments', 'qualifications', 'experience', 'certifications', 'documents'] as $section) {
            $this->assertSame([], $data[$section], "Section '{$section}' must be [] when empty, not null.");
        }
    }

    #[Test]
    public function no_php_enum_or_class_name_leaks_into_status_fields(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->getContent();

        foreach (['App\\Domain', 'Enum', '::class', 'stdClass'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    #[Test]
    public function pagination_meta_has_exactly_the_accepted_keys(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $this->assertSame(['page', 'perPage', 'total'], array_keys($response->json('meta')));
        $this->assertIsInt($response->json('meta.page'));
        $this->assertIsInt($response->json('meta.perPage'));
        $this->assertIsInt($response->json('meta.total'));
    }
}
