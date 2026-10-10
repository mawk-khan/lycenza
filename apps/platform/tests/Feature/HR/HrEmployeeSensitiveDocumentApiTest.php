<?php

namespace Tests\Feature\HR;

use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED HTTP contract proof for the Highly Sensitive
 * document metadata API
 * (`GET /api/v1/schools/{school}/employees/{employee}/sensitive-documents`),
 * checkpoint brief section 74. A thin adapter over the already-audited,
 * already-authorized `EmployeeSensitiveDocumentReadService` -- these
 * tests prove the HTTP transport preserves that behavior exactly, not
 * that the service itself is correct (already proven in 8A.10).
 */
class HrEmployeeSensitiveDocumentApiTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private function token($user): string
    {
        return $this->mfaToken($user);
    }

    #[Test]
    public function sensitive_view_capability_is_required(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $ordinaryDocumentsActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryDocumentsActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertForbidden();
    }

    #[Test]
    public function school_a_permission_cannot_read_a_school_b_employees_sensitive_documents(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $this->createEmployeeDocument($employeeB, ['classification_tier' => 'highly_sensitive']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeB->id}/sensitive-documents")
            ->assertNotFound();
    }

    #[Test]
    public function a_sensitive_view_actor_receives_exactly_the_safe_document_keys(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'highly_sensitive',
            'category' => 'government_id',
            'storage_path' => 'sentinel/secret/path.pdf',
            'storage_disk' => 'sentinel-disk',
        ]);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertOk();

        $entry = $response->json('data.0');
        $this->assertSame(['id', 'category', 'classification_tier', 'issued_on', 'expires_on', 'status'], array_keys($entry));
        $this->assertSame('highly_sensitive', $entry['classification_tier']);
    }

    #[Test]
    public function no_storage_path_disk_or_file_url_ever_appears(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'highly_sensitive',
            'storage_path' => 'sentinel/secret/path.pdf',
            'storage_disk' => 'sentinel-disk-name',
        ]);
        $actor = $this->fullHrActor($school);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertOk()
            ->getContent();

        foreach (['storage_path', 'storage_disk', 'sentinel/secret/path.pdf', 'sentinel-disk-name', 'url', 'signed'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "Forbidden field/value '{$forbidden}' leaked into the sensitive document API response.");
        }
    }

    #[Test]
    public function a_successful_non_empty_read_is_audited_exactly_once(): void
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

    #[Test]
    public function an_empty_read_is_not_audited(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertOk();

        $this->assertSame([], $response->json('data'));

        app(TenantContext::class)->set($school);
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'hr.employee_document.sensitive_viewed')->count());
    }

    #[Test]
    public function unauthorized_denial_leaks_no_information_about_document_existence(): void
    {
        $school = $this->createSchool();
        $employeeWithDocs = $this->createEmployee($school);
        $this->createEmployeeDocument($employeeWithDocs, ['classification_tier' => 'highly_sensitive']);
        $employeeWithoutDocs = $this->createEmployee($school);
        $unauthorizedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $withDocsResponse = $this->withHeader('Authorization', 'Bearer '.$this->token($unauthorizedActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employeeWithDocs->id}/sensitive-documents");
        $withoutDocsResponse = $this->withHeader('Authorization', 'Bearer '.$this->token($unauthorizedActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employeeWithoutDocs->id}/sensitive-documents");

        $withDocsResponse->assertForbidden();
        $withoutDocsResponse->assertForbidden();
        $this->assertSame($withDocsResponse->json('error.message'), $withoutDocsResponse->json('error.message'), 'Identical denial regardless of whether sensitive documents actually exist.');
    }
}
