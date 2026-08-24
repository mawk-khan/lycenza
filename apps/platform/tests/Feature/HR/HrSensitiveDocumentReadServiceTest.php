<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeProfileDocumentEntry;
use App\Domain\HR\Application\EmployeeSensitiveDocumentReadService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.10 -- the narrow, separately-authorized Highly Sensitive
 * EmployeeDocument metadata read path
 * (App\Domain\HR\Application\EmployeeSensitiveDocumentReadService).
 * Requires `hr.employees.sensitive.view` specifically -- never
 * satisfied by `hr.employees.documents.view`/`.manage` or
 * `hr.employees.personal.view` alone. Audits exactly one logical event
 * per successful non-empty read.
 */
class HrSensitiveDocumentReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function sensitive_view_returns_only_highly_sensitive_documents_in_the_safe_shape(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted', 'category' => 'id_proof']);
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'highly_sensitive',
            'category' => 'background_check',
            'original_filename' => 'top-secret.pdf',
            'storage_path' => 'employee-documents/top-secret.pdf',
        ]);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);

        $entries = app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);

        $this->assertCount(1, $entries);
        $this->assertInstanceOf(EmployeeProfileDocumentEntry::class, $entries[0]);
        $this->assertSame('highly_sensitive', $entries[0]->classificationTier);
        $this->assertSame('background_check', $entries[0]->category);

        $serialized = json_encode($entries[0]->toArray());
        $this->assertStringNotContainsString('top-secret.pdf', $serialized, 'The safe metadata shape must never include original_filename/storage_path.');
    }

    #[Test]
    public function documents_view_alone_does_not_grant_sensitive_read_access(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.view', 'hr.employees.documents.manage']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);
    }

    #[Test]
    public function personal_view_alone_does_not_grant_sensitive_read_access(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);
    }

    #[Test]
    public function a_cross_school_employee_id_returns_null_not_another_schools_data(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $this->createEmployeeDocument($employeeB, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.sensitive.view']);

        $result = app(EmployeeSensitiveDocumentReadService::class)->forEmployee($schoolA, $employeeB->id, $actor);

        $this->assertNull($result);
    }

    #[Test]
    public function a_successful_non_empty_read_is_audited_exactly_once(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);

        app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);

        app(TenantContext::class)->set($school);
        $events = SchoolAuditEvent::query()->where('event_type', 'hr.employee_document.sensitive_viewed')->get();

        $this->assertCount(1, $events, 'Exactly one logical audit event, never one per document.');
        $this->assertSame($actor->id, $events->first()->actor_user_id);
        $this->assertSame($employee->id, $events->first()->metadata['employeeId']);
        $this->assertCount(2, $events->first()->metadata['documentIds']);
        $this->assertArrayNotHasKey('storage_path', $events->first()->metadata);
        $this->assertArrayNotHasKey('original_filename', $events->first()->metadata);
    }

    #[Test]
    public function a_read_that_finds_no_highly_sensitive_documents_is_not_audited(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);

        $entries = app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);

        $this->assertSame([], $entries);

        app(TenantContext::class)->set($school);
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'hr.employee_document.sensitive_viewed')->count());
    }
}
