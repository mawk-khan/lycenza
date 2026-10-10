<?php

namespace Tests\Feature\HR;

use App\Models\Role;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * Phase 8A.15 -- REQUIRED rate-limit proof (checkpoint brief sections
 * 6-11/64/66): closes the 8A.14 "no dedicated/shared read limiter"
 * finding. Reuses `RateLimiterServiceProvider::tenantKey()` (School +
 * actor, never IP-only) via two new named limiters
 * (`hr-api-reads` 120/min, `hr-api-sensitive-reads` 20/min) --
 * mirrors `SchoolApiMutationsThrottleTest`'s established real-HTTP
 * proof style, not a synthetic keying-only test.
 */
class HrEmployeeApiRateLimitTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private function token($user): string
    {
        return $this->mfaToken($user);
    }

    #[Test]
    public function directory_is_throttled_at_120_per_minute(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertStatus(429);
    }

    #[Test]
    public function profile_and_timeline_share_the_same_120_per_minute_hr_api_reads_bucket(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        // The bucket is per (School, actor), not per-route -- mixing
        // Directory/Profile/Timeline calls still exhausts one shared
        // `hr-api-reads` quota (checkpoint section 11: query-string/
        // route changes must not trivially create a new bucket).
        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees")
                ->assertOk();
        }
        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertStatus(429);
    }

    #[Test]
    public function changing_the_search_query_string_does_not_create_a_new_bucket(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees?search=distinct-query-{$i}")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees?search=one-more")
            ->assertStatus(429);
    }

    #[Test]
    public function sensitive_documents_use_the_stricter_20_per_minute_limiter(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 20; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertStatus(429);
    }

    #[Test]
    public function exhausting_the_sensitive_limiter_does_not_affect_the_general_hr_api_reads_bucket(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 21; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents");
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();
    }

    #[Test]
    public function a_429_uses_the_standard_error_envelope_with_retry_after(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees");
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertStatus(429);

        $this->assertSame(['message', 'status', 'code', 'requestId', 'errors'], array_keys($response->json('error')));
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertNull($response->json('error.errors'));
    }

    #[Test]
    public function throttling_response_leaks_no_personal_or_sensitive_data(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['full_name' => 'Throttle Sentinel Person']);
        $this->createEmployeePersonalDetail($employee, ['personal_email' => 'throttle-sentinel@example.com']);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}");
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertStatus(429);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('Throttle Sentinel Person', $raw);
        $this->assertStringNotContainsString('throttle-sentinel@example.com', $raw);
    }

    #[Test]
    public function the_same_user_has_independent_buckets_per_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $membershipA = $this->createMembership($user, $schoolA);
        $membershipB = $this->createMembership($user, $schoolB);

        foreach ([$membershipA, $membershipB] as $membership) {
            $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create([
                'key' => 'test.hr_view.'.Str::uuid(),
                'name' => 'Test HR View',
                'scope' => 'school',
                'is_system' => false,
            ]));
            LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['hr.employees.view']));
            $this->assignSchoolRole($membership, $role->key);
        }

        $token = $this->token($user);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$schoolA->id}/employees")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolA->id}/employees")
            ->assertStatus(429);

        // School B's bucket for the SAME User is completely untouched.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolB->id}/employees")
            ->assertOk();
    }

    #[Test]
    public function different_users_in_the_same_school_have_independent_buckets(): void
    {
        $school = $this->createSchool();
        $actorA = $this->fullHrActor($school);
        $actorB = $this->fullHrActor($school);
        $tokenA = $this->token($actorA);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$tokenA)
                ->getJson("/api/v1/schools/{$school->id}/employees")
                ->assertOk();
        }
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertStatus(429);

        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token($actorB))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();
    }

    #[Test]
    public function unauthorized_denial_against_an_employee_with_sensitive_documents_consumes_the_same_bucket_shape_as_one_without(): void
    {
        $school = $this->createSchool();
        $employeeWithDocs = $this->createEmployee($school);
        $this->createEmployeeDocument($employeeWithDocs, ['classification_tier' => 'highly_sensitive']);
        $employeeWithoutDocs = $this->createEmployee($school);
        $unauthorizedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);
        $token = $this->token($unauthorizedActor);

        // The unauthorized-denial (403) path uses the SAME
        // `hr-api-sensitive-reads` limiter regardless of whether the
        // target Employee happens to have sensitive documents -- the
        // limiter never branches on hidden resource state (checkpoint
        // section 51).
        for ($i = 0; $i < 20; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees/{$employeeWithDocs->id}/sensitive-documents")
                ->assertForbidden();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employeeWithoutDocs->id}/sensitive-documents")
            ->assertStatus(429);
    }

    #[Test]
    public function normal_pagination_usage_remains_practical_under_the_limit(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees?page=".($i + 1))
                ->assertOk();
        }
    }
}
