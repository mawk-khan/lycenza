<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.10 -- REQUIRED classification-transition bypass proof
 * (checkpoint brief sections 9-12/56): `hr.employees.documents.manage`
 * is sufficient ONLY when a document stays `restricted`. Any operation
 * that touches a `highly_sensitive` document at all -- update, archive,
 * or a transition in EITHER direction -- requires
 * `hr.employees.sensitive.manage`, non-negotiably. A denied attempt
 * must leave the record's classification_tier completely unchanged.
 */
class HrDocumentClassificationAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function ordinary_documents_manage_can_register_a_restricted_document(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'classification_tier' => 'restricted',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ], $actor);

        $this->assertSame('restricted', $document->classification_tier);
    }

    #[Test]
    public function ordinary_documents_manage_cannot_register_a_highly_sensitive_document(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'background_check',
            'classification_tier' => 'highly_sensitive',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ], $actor);
    }

    #[Test]
    public function sensitive_manage_can_register_a_highly_sensitive_document(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'background_check',
            'classification_tier' => 'highly_sensitive',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ], $actor);

        $this->assertSame('highly_sensitive', $document->classification_tier);
    }

    #[Test]
    public function ordinary_documents_manage_cannot_upgrade_restricted_to_highly_sensitive(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        try {
            app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'highly_sensitive'], $actor);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $this->assertSame('restricted', $document->fresh()->classification_tier, 'A denied upgrade attempt must leave the record completely unchanged.');
    }

    #[Test]
    public function ordinary_documents_manage_cannot_downgrade_highly_sensitive_to_restricted(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        try {
            app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'restricted'], $actor);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $this->assertSame('highly_sensitive', $document->fresh()->classification_tier, 'A denied downgrade attempt must leave the record completely unchanged -- this is the exact bypass this checkpoint closes.');
    }

    #[Test]
    public function sensitive_manage_can_perform_both_classification_transitions(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);

        $upgraded = app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'highly_sensitive'], $actor);
        $this->assertSame('highly_sensitive', $upgraded->classification_tier);

        $downgraded = app(EmployeeDocumentService::class)->update($employee, $upgraded, ['classification_tier' => 'restricted'], $actor);
        $this->assertSame('restricted', $downgraded->classification_tier);
    }

    #[Test]
    public function ordinary_documents_manage_cannot_update_a_non_classification_field_on_a_highly_sensitive_document(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive', 'category' => 'background_check']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(AuthorizationException::class);

        // No classification_tier key at all -- an ordinary document
        // manager must still be blocked from touching ANY field on an
        // already-highly_sensitive record (section 12).
        app(EmployeeDocumentService::class)->update($employee, $document, ['category' => 'other'], $actor);
    }

    #[Test]
    public function ordinary_documents_manage_cannot_archive_a_highly_sensitive_document(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        try {
            app(EmployeeDocumentService::class)->archive($employee, $document, $actor);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $this->assertSame('active', $document->fresh()->status, 'A denied archive attempt must leave the record unchanged -- archiving must never be usable as a side door around the classification boundary.');
    }

    #[Test]
    public function sensitive_manage_can_archive_a_highly_sensitive_document(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);

        $archived = app(EmployeeDocumentService::class)->archive($employee, $document, $actor);

        $this->assertSame('archived', $archived->status);
        $this->assertSame('highly_sensitive', $archived->classification_tier, 'Archiving must never itself change classification.');
    }

    #[Test]
    public function an_archived_highly_sensitive_document_still_requires_sensitive_manage_to_touch(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive', 'status' => 'archived']);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeDocumentService::class)->update($employee, $document, ['category' => 'other'], $actor);
    }
}
