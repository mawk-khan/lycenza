<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentNotFoundException;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerTypeNotSupportedException;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.3 -- DocumentReadService::metadata().
 */
class DocumentReadServiceMetadataTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function writeService(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function readService(): DocumentReadService
    {
        return app(DocumentReadService::class);
    }

    private function createDocument($school, $employee, $actor, string $tier = 'internal'): Document
    {
        return app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function metadata_returns_a_safe_dto_with_no_storage_path_or_disk(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor);

        $metadata = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $actor));

        $this->assertSame($document->id, $metadata->documentId);
        $this->assertSame('employee', $metadata->ownerType);
        $this->assertSame($employee->id, $metadata->ownerId);
        $this->assertSame('internal', $metadata->classificationTier);
        $this->assertSame('active', $metadata->status);
        $this->assertSame('report.pdf', $metadata->originalFilename);
        $this->assertSame('application/pdf', $metadata->mimeType);
        $this->assertGreaterThan(0, $metadata->sizeBytes);

        $this->assertFalse(property_exists($metadata, 'storagePath'));
        $this->assertFalse(property_exists($metadata, 'storage_path'));
        $this->assertFalse(property_exists($metadata, 'storageDisk'));
        $this->assertFalse(property_exists($metadata, 'uploadedByUserId'));
    }

    #[Test]
    public function metadata_lookup_does_not_touch_storage(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor);

        Storage::shouldReceive('disk')->never();

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $actor));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function unauthorized_metadata_lookup_is_denied_and_touches_no_storage(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $unauthorized = $this->createUserWithCapabilities($school, []);
        $document = $this->createDocument($school, $employee, $owner);

        Storage::shouldReceive('disk')->never();

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $unauthorized));
    }

    #[Test]
    public function a_nonexistent_document_id_is_rejected_safely(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);

        $this->expectException(DocumentNotFoundException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, (string) new UuidV7, $actor));
    }

    #[Test]
    public function a_cross_school_document_id_is_rejected_identically_to_nonexistent(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $ownerB = $this->createUserWithCapabilities($schoolB, ['hr.employees.documents.manage']);
        $documentB = $this->createDocument($schoolB, $employeeB, $ownerB);

        $actorA = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.view']);

        $this->expectException(DocumentNotFoundException::class);

        app(TenantContext::class)->withSchool($schoolA, fn () => $this->readService()->metadata($schoolA, $documentB->id, $actorA));
    }

    #[Test]
    public function cross_domain_capability_does_not_grant_employee_document_metadata_access(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $studentManager = $this->createUserWithCapabilities($school, ['students.manage', 'students.view']);
        $document = $this->createDocument($school, $employee, $owner);

        Storage::shouldReceive('disk')->never();

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $studentManager));
    }

    #[Test]
    public function unsupported_student_owner_documents_are_rejected_without_touching_storage(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $actor = $this->createUserWithCapabilities($school, ['students.manage', 'students.view', 'hr.employees.documents.view']);
        $document = $this->createDocumentForStudent($student);

        Storage::shouldReceive('disk')->never();

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $actor));
            $this->fail('Expected DocumentOwnerTypeNotSupportedException.');
        } catch (DocumentOwnerTypeNotSupportedException $e) {
            $this->assertSame('student', $e->ownerType);
        }
    }

    #[Test]
    public function unsupported_guardian_owner_documents_are_rejected_without_touching_storage(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $actor = $this->createUserWithCapabilities($school, ['guardians.manage', 'guardians.view', 'hr.employees.documents.view']);
        $document = $this->createDocumentForGuardian($guardian);

        Storage::shouldReceive('disk')->never();

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $actor));
            $this->fail('Expected DocumentOwnerTypeNotSupportedException.');
        } catch (DocumentOwnerTypeNotSupportedException $e) {
            $this->assertSame('guardian', $e->ownerType);
        }
    }

    #[Test]
    public function same_user_multiple_schools_capability_does_not_bleed(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $ownerB = $this->createUserWithCapabilities($schoolB, ['hr.employees.documents.manage']);
        $documentB = $this->createDocument($schoolB, $employeeB, $ownerB);

        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.view']);
        $this->createMembership($actor, $schoolB);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($schoolB, fn () => $this->readService()->metadata($schoolB, $documentB->id, $actor));
    }

    #[Test]
    public function rls_and_authorization_are_independent_layers(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $document = $this->createDocument($school, $employee, $owner);

        // (A) same-School visible Document, actor lacks capability -> denied.
        $noCapability = $this->createUserWithCapabilities($school, []);
        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $noCapability));
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // (B) actor has capability in School A, Document belongs to School B -> not-found.
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $ownerB = $this->createUserWithCapabilities($schoolB, ['hr.employees.documents.manage']);
        $documentB = $this->createDocument($schoolB, $employeeB, $ownerB);
        $capableInA = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);

        $this->expectException(DocumentNotFoundException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $documentB->id, $capableInA));
    }

    #[Test]
    public function every_classification_tier_requires_the_correct_read_capability(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.sensitive.manage']);
        $ordinaryReader = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);
        $sensitiveReader = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);

        foreach (['public', 'internal', 'sensitive'] as $tier) {
            $document = $this->createDocument($school, $employee, $writer, $tier);

            $metadata = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $ordinaryReader));
            $this->assertSame($tier, $metadata->classificationTier);

            try {
                app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $sensitiveReader));
                $this->fail("Expected AuthorizationException for sensitive-only reader on {$tier}.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $highlySensitive = $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        $metadata = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $highlySensitive->id, $sensitiveReader));
        $this->assertSame('highly_sensitive', $metadata->classificationTier);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $highlySensitive->id, $ordinaryReader));
            $this->fail('Expected AuthorizationException for ordinary reader on highly_sensitive.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function ordinary_tier_metadata_access_is_not_audited(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor, 'internal');

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $actor));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_metadata_viewed')->count(),
        );
        $this->assertSame(0, $count);
    }

    #[Test]
    public function highly_sensitive_metadata_access_is_audited_exactly_once(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $reader = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $reader));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'document.sensitive_metadata_viewed')
                ->where('subject_id', $document->id)
                ->where('actor_user_id', $reader->id)
                ->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function denied_highly_sensitive_metadata_access_produces_no_audit_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $ordinaryReader = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $ordinaryReader));
        } catch (AuthorizationException) {
            // expected
        }

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_metadata_viewed')->count(),
        );
        $this->assertSame(0, $count);
    }

    #[Test]
    public function an_archived_document_remains_readable(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor);

        app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->archive($school, $document, $actor));

        $metadata = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->metadata($school, $document->id, $actor));

        $this->assertSame('archived', $metadata->status);
    }
}
