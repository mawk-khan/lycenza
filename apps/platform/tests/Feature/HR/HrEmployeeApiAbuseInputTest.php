<?php

namespace Tests\Feature\HR;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.15 -- REQUIRED API parameter-abuse proof (checkpoint brief
 * sections 32-36/69). Every case here already resolves safely by
 * CONSTRUCTION -- `EmployeeDirectoryController`/`EmployeeActivityController`
 * validate every transport parameter with a scalar Laravel rule
 * (`string`/`integer`/`boolean`) before it ever reaches
 * `EmployeeDirectoryQuery`/`EmployeeActivityTimelineQuery`, and both
 * Query objects independently clamp/allow-list `sort`/`direction`/
 * `per_page`/`category`. These tests are evidence, not new production
 * code -- no controller/validation change was needed to pass them.
 */
class HrEmployeeApiAbuseInputTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function an_overlong_directory_search_string_is_rejected_with_422_not_500(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?search=".str_repeat('a', 300))
            ->assertStatus(422);
    }

    #[Test]
    public function a_search_string_at_the_documented_maximum_length_is_accepted(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?search=".str_repeat('a', 255))
            ->assertOk();
    }

    #[Test]
    public function array_valued_search_is_rejected_not_a_500(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?search[]=a&search[]=b")
            ->assertStatus(422);
    }

    #[Test]
    public function array_valued_sort_is_rejected_not_a_500(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?sort[]=full_name&sort[]=employee_number")
            ->assertStatus(422);
    }

    #[Test]
    public function array_valued_per_page_is_rejected_not_a_500(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?per_page[]=100")
            ->assertStatus(422);
    }

    #[Test]
    public function array_valued_category_on_timeline_is_rejected_not_a_500(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?category[]=document&category[]=personal")
            ->assertStatus(422);
    }

    #[Test]
    public function a_huge_per_page_integer_is_safely_clamped_not_an_error(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?per_page=999999999")
            ->assertOk();

        $this->assertSame(100, $response->json('meta.perPage'));
    }

    #[Test]
    public function an_integer_beyond_php_int_range_is_rejected_as_invalid_not_a_500(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?per_page=99999999999999999999999999999999")
            ->assertStatus(422);
    }

    #[Test]
    public function a_negative_page_is_rejected_with_422(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?page=-1")
            ->assertStatus(422);
    }

    #[Test]
    public function a_zero_page_is_rejected_with_422(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?page=0")
            ->assertStatus(422);
    }

    #[Test]
    public function a_non_integer_page_is_rejected_with_422(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?page=abc")
            ->assertStatus(422);
    }

    #[Test]
    public function a_malformed_boolean_include_archived_is_rejected_with_422(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?include_archived=maybe")
            ->assertStatus(422);
    }

    #[Test]
    public function a_non_uuid_looking_filter_id_is_rejected_with_422_not_a_raw_sql_error(): void
    {
        // Confirmed empirically during this checkpoint: campus_id/
        // department_id/position_id feed a UUID-typed database column
        // (App\Domain\HR\Application\EmployeeDirectoryService's
        // whereExists subquery). Before this checkpoint's `uuid`
        // validation rule, this input reached PostgreSQL as a raw
        // `invalid input syntax for type uuid` QueryException -- an
        // unhandled 500, not a safe result.
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?position_id=not-a-real-uuid-at-all")
            ->assertStatus(422);
    }

    #[Test]
    public function a_well_formed_but_nonexistent_or_foreign_school_filter_id_still_yields_zero_results_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->fullHrActor($schoolA);
        $this->createEmployee($schoolA);
        $foreignPosition = $this->createPosition($schoolB);

        $nonexistent = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees?position_id=".Str::uuid())
            ->assertOk();
        $this->assertSame([], $nonexistent->json('data'));

        $foreignSchool = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees?position_id={$foreignPosition->id}")
            ->assertOk();
        $this->assertSame([], $foreignSchool->json('data'));
    }

    #[Test]
    public function a_malformed_employee_id_on_profile_activity_and_sensitive_endpoints_is_a_safe_404_not_a_raw_sql_error(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/not-a-real-uuid")
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/not-a-real-uuid/activity")
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/not-a-real-uuid/sensitive-documents")
            ->assertNotFound();
    }

    #[Test]
    public function a_malformed_timeline_date_is_rejected_with_422(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?occurred_from=not-a-date")
            ->assertStatus(422);
    }

    #[Test]
    public function a_reversed_timeline_date_range_yields_zero_results_not_an_error(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?occurred_from=2026-12-31&occurred_to=2026-01-01")
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    #[Test]
    public function an_unrecognized_category_string_is_treated_as_no_filter_never_a_422(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?category=".urlencode('"; DROP TABLE school_audit_events; --'))
            ->assertOk();
    }

    #[Test]
    public function a_sql_metacharacter_laden_sort_value_is_safely_ignored(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?".http_build_query(['sort' => 'id) UNION SELECT * FROM users --']))
            ->assertOk();
    }
}
