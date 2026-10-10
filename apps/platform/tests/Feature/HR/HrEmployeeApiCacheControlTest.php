<?php

namespace Tests\Feature\HR;

use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * Phase 8A.15 -- REQUIRED Cache-Control proof (checkpoint brief
 * sections 12-15/49/65/67): closes the 8A.14 "no explicit privacy-
 * oriented Cache-Control policy" finding via the new, generic
 * `App\Http\Middleware\Api\EnsurePrivateNoStoreResponse` (`private-no-store`
 * alias), applied to all four HR read routes.
 */
class HrEmployeeApiCacheControlTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private function token($user): string
    {
        return $this->mfaToken($user);
    }

    #[Test]
    public function directory_success_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $this->assertCacheControlIsPrivateNoStore($response);
    }

    #[Test]
    public function profile_success_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk();

        $this->assertCacheControlIsPrivateNoStore($response);
    }

    #[Test]
    public function timeline_success_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertOk();

        $this->assertCacheControlIsPrivateNoStore($response);
    }

    #[Test]
    public function sensitive_documents_success_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertOk();

        $this->assertCacheControlIsPrivateNoStore($response);
    }

    #[Test]
    public function a_forbidden_profile_response_still_carries_the_private_no_store_policy(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $member = $this->createUserWithCapabilities($school, []);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($member))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertForbidden();

        // The 403 is thrown BY the controller's own service call, so it
        // still passes back out through `private-no-store` (unlike a
        // 429/401, which short-circuit before this route's middleware
        // stack is ever entered -- see the rate-limit test file).
        $this->assertCacheControlIsPrivateNoStore($response);
    }

    #[Test]
    public function a_not_found_profile_response_still_carries_the_private_no_store_policy(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/".Str::uuid())
            ->assertNotFound();

        $this->assertCacheControlIsPrivateNoStore($response);
    }

    #[Test]
    public function an_invalid_query_parameter_422_response_still_carries_the_private_no_store_policy(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?page=0")
            ->assertStatus(422);

        $this->assertCacheControlIsPrivateNoStore($response);
    }

    #[Test]
    public function no_response_ever_carries_a_contradictory_public_or_shared_cache_directive(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        $responses = [
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees"),
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}"),
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity"),
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents"),
        ];

        foreach ($responses as $response) {
            $header = strtolower((string) $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('public', $header);
            $this->assertStringNotContainsString('s-maxage', $header);
            $this->assertStringNotContainsString('max-age', $header);
        }
    }

    #[Test]
    public function the_cache_control_middleware_never_alters_the_json_body(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('meta', $response->json());
        $this->assertSame(['page', 'perPage', 'total'], array_keys($response->json('meta')));
    }

    #[Test]
    public function a_sensitive_document_read_under_the_new_middleware_is_still_audited_exactly_once(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertOk();

        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'hr.employee_document.sensitive_viewed')->count());
    }

    private function assertCacheControlIsPrivateNoStore(TestResponse $response): void
    {
        $header = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('no-store', $header);
    }
}
