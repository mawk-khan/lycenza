<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED N+1/query-bound proof (checkpoint brief
 * section 78). A full HTTP round trip carries fixed baseline overhead
 * that a bare service call does not (Sanctum token lookup, actor
 * hydration, `EnsureSchoolMembershipContext`'s membership check,
 * several independent `CapabilityResolver` checks) -- comparing an
 * absolute query count against a small constant (as
 * `HrActivityTimelinePaginationTest` does for the bare service) would
 * either be too strict for the real HTTP path or too loose to catch a
 * genuine per-item regression. The actual N+1 definition this
 * checkpoint cares about is proven correctly by a DIFFERENTIAL
 * comparison instead: query count for a FEW items vs. query count for
 * MANY items, asserting the difference stays small -- this is exactly
 * what "no query per serialized item" means, and it is robust to
 * whatever fixed per-request overhead the auth/tenancy/authorization
 * stack legitimately carries.
 */
class HrEmployeeApiPerformanceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function queryCountFor(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function directory_query_count_does_not_scale_with_employee_count(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);
        $seed = function (int $count) use ($school, $actor, $position) {
            for ($i = 0; $i < $count; $i++) {
                $employee = $this->createEmployee($school);
                $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
                app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $actor);
            }
        };
        $token = $this->token($actor);

        $seed(2);
        $small = $this->queryCountFor(fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees?per_page=100")->assertOk());

        $seed(18);
        $large = $this->queryCountFor(fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees?per_page=100")->assertOk());

        $this->assertLessThanOrEqual(3, $large - $small, 'Query count grew by '.($large - $small).' between 2 and 20 Employees -- suggests a per-item query.');
    }

    #[Test]
    public function profile_query_count_does_not_scale_with_history_row_count(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        $this->createEmployeeQualification($employee);
        $token = $this->token($actor);

        // Warm every cache (capability resolution, Sanctum token
        // lookup) with an initial throwaway call, THEN measure -- both
        // "small" and "large" calls below hit the identical Employee,
        // identical actor, identical endpoint; the ONLY thing that
        // changes between them is the number of qualification/
        // certification rows, isolating exactly the per-item query
        // question this test asks.
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}");

        $small = $this->queryCountFor(fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")->assertOk());

        for ($i = 0; $i < 15; $i++) {
            $this->createEmployeeQualification($employee);
            $this->createEmployeeCertification($employee);
        }

        $large = $this->queryCountFor(fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")->assertOk());

        $this->assertLessThanOrEqual(3, $large - $small, 'Query count grew by '.($large - $small).' after adding 30 more history rows to the SAME Employee -- suggests a per-item query.');
    }

    #[Test]
    public function timeline_query_count_does_not_scale_with_event_count(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Performance Timeline Test'], $actor);
        $token = $this->token($actor);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity");

        $small = $this->queryCountFor(fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?per_page=100")->assertOk());

        for ($i = 0; $i < 15; $i++) {
            $distinctActor = $this->createUserWithCapabilities($school, ['hr.employees.qualifications.manage']);
            app(EmployeeQualificationService::class)->add($employee, [
                'qualification_type' => 'bachelors', 'qualification_name' => "Qualification {$i}", 'institution' => 'Test University',
            ], $distinctActor);
        }

        $large = $this->queryCountFor(fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?per_page=100")->assertOk());

        $this->assertLessThanOrEqual(3, $large - $small, 'Query count grew by '.($large - $small)." after adding 15 more events (each from a DISTINCT actor) to the SAME Employee's timeline -- suggests a per-event actor lookup.");
    }

    #[Test]
    public function directory_pagination_remains_bounded_even_with_many_employees(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        for ($i = 0; $i < 30; $i++) {
            $this->createEmployee($school);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $this->assertCount(25, $response->json('data'), 'Default page size must still apply -- no unbounded collection is ever returned.');
        $this->assertSame(30, $response->json('meta.total'));
    }
}
