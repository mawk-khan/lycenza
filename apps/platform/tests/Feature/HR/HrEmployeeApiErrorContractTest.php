<?php

namespace Tests\Feature\HR;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED error-contract proof (checkpoint brief
 * section 77): every HR API failure uses the SAME global JSON error
 * envelope already established by `bootstrap/app.php`
 * (docs/architecture/API.md "Error format") -- this checkpoint
 * introduces no parallel error shape and no HR-specific leakage.
 */
class HrEmployeeApiErrorContractTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private function token($user): string
    {
        return $this->mfaToken($user);
    }

    #[Test]
    public function an_unauthenticated_request_uses_the_standard_error_envelope(): void
    {
        $school = $this->createSchool();

        $response = $this->getJson("/api/v1/schools/{$school->id}/employees")->assertUnauthorized();

        $this->assertSame(['message', 'status', 'code', 'requestId', 'errors'], array_keys($response->json('error')));
        $this->assertSame(401, $response->json('error.status'));
    }

    #[Test]
    public function an_unauthorized_request_uses_the_standard_error_envelope(): void
    {
        $school = $this->createSchool();
        $member = $this->createUserWithCapabilities($school, []);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($member))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertForbidden();

        $this->assertSame(['message', 'status', 'code', 'requestId', 'errors'], array_keys($response->json('error')));
        $this->assertSame(403, $response->json('error.status'));
    }

    #[Test]
    public function a_nonexistent_employee_uses_the_standard_error_envelope(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/".Str::uuid())
            ->assertNotFound();

        $this->assertSame(404, $response->json('error.status'));
    }

    #[Test]
    public function a_cross_school_employee_is_indistinguishable_from_a_nonexistent_one(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $employeeB = $this->createEmployee($schoolB);

        $crossSchool = $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeB->id}");
        $nonexistent = $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/".Str::uuid());

        $crossSchool->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame($nonexistent->json('error.status'), $crossSchool->json('error.status'));
    }

    #[Test]
    public function invalid_pagination_parameters_return_a_422_not_a_500(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?page=0")
            ->assertStatus(422);

        $this->assertNotNull($response->json('error.errors'));
    }

    #[Test]
    public function an_invalid_date_filter_returns_a_422_not_a_500(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?occurred_from=not-a-date")
            ->assertStatus(422);
    }

    #[Test]
    public function no_error_response_ever_leaks_a_sqlstate_or_stack_trace(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?page=not-a-number")
            ->assertStatus(422);

        $raw = $response->getContent();
        foreach (['SQLSTATE', 'PDOException', 'QueryException', 'Stack trace', '.php:', 'vendor/laravel'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "Forbidden internal detail '{$forbidden}' leaked into an HR API error response.");
        }
    }

    #[Test]
    public function no_error_response_ever_leaks_personal_data(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['full_name' => 'Error Sentinel Person']);
        $this->createEmployeePersonalDetail($employee, ['personal_email' => 'error-sentinel@example.com']);
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryMember))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertForbidden();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('Error Sentinel Person', $raw);
        $this->assertStringNotContainsString('error-sentinel@example.com', $raw);
    }

    #[Test]
    public function a_denied_sensitive_read_leaks_no_hidden_sensitive_identifier(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $ordinaryDocumentsActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryDocumentsActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertForbidden();

        $this->assertStringNotContainsString($document->id, $response->getContent());
    }
}
