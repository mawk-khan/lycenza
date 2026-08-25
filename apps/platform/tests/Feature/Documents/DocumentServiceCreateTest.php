<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerNotFoundException;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerTypeNotSupportedException;
use App\Domain\Documents\Application\Exceptions\DocumentStorageException;
use App\Domain\Documents\Application\Exceptions\DocumentTooLargeException;
use App\Domain\Documents\Application\Exceptions\DocumentTypeNotAllowedException;
use App\Domain\Documents\Application\Exceptions\InvalidDocumentClassificationException;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.2 -- DocumentService::create(), exercised directly (not via
 * HTTP, since none exists) so validation/authorization order and
 * exact failure modes can be asserted precisely. Mirrors
 * CommunicationAttachmentServiceTest's own structure and Storage::fake()
 * convention.
 */
class DocumentServiceCreateTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function documentCount($school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => Document::query()->count());
    }

    private function auditCount($school, string $eventType): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->count(),
        );
    }

    #[Test]
    public function a_permitted_employee_document_is_accepted_and_stored_privately(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(
                DocumentOwner::employee($employee->id),
                'internal',
                UploadedFile::fake()->create('id-card.pdf', 100, 'application/pdf'),
            ),
            $actor,
        ));

        $this->assertSame('employee', $document->owner_type);
        $this->assertSame($employee->id, $document->employee_id);
        $this->assertSame('internal', $document->classification_tier);
        $this->assertSame('active', $document->status);
        $this->assertSame('id-card.pdf', $document->original_filename);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertStringStartsWith("schools/{$school->id}/documents/employee/{$employee->id}/", $document->storage_path);

        Storage::disk('local')->assertExists($document->storage_path);
    }

    #[Test]
    public function authorization_failure_writes_no_object_and_no_row(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $unauthorizedActor = $this->createUserWithCapabilities($school, []);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(
                    DocumentOwner::employee($employee->id),
                    'internal',
                    UploadedFile::fake()->create('id-card.pdf', 100, 'application/pdf'),
                ),
                $unauthorizedActor,
            ));
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->documentCount($school));
        $this->assertSame(0, $this->auditCount($school, 'document.created'));
        Storage::disk('local')->assertDirectoryEmpty("schools/{$school->id}");
    }

    #[Test]
    public function highly_sensitive_requires_the_sensitive_capability_not_the_ordinary_documents_capability(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $ordinaryActor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $sensitiveActor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'highly_sensitive', UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')),
                $ordinaryActor,
            ));
            $this->fail('Expected AuthorizationException for ordinary documents.manage on a highly_sensitive create.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'highly_sensitive', UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')),
            $sensitiveActor,
        ));

        $this->assertSame('highly_sensitive', $document->classification_tier);
        $this->assertSame(1, $this->documentCount($school));
    }

    #[Test]
    public function cross_domain_authorization_is_not_bypassed_by_a_generic_documents_capability(): void
    {
        // Critical test (checklist §60): an actor with a strong
        // permission for one owner domain (here: broad Student
        // management) must NOT be able to write an Employee-owned
        // Document merely by attempting it -- there is no generic
        // "documents.manage" capability in this checkpoint at all
        // (Option A, docs/modules/DOCUMENTS.md), so this also proves
        // no such capability was silently introduced.
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $studentManager = $this->createUserWithCapabilities($school, ['students.manage', 'students.view']);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
                $studentManager,
            ));
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->documentCount($school));
    }

    #[Test]
    public function student_owner_type_is_deferred_not_silently_allowed(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $actor = $this->createUserWithCapabilities($school, ['students.manage', 'hr.employees.documents.manage']);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::student($student->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
                $actor,
            ));
            $this->fail('Expected DocumentOwnerTypeNotSupportedException.');
        } catch (DocumentOwnerTypeNotSupportedException $e) {
            $this->assertSame('student', $e->ownerType);
        }

        $this->assertSame(0, $this->documentCount($school));
    }

    #[Test]
    public function guardian_owner_type_is_deferred_not_silently_allowed(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $actor = $this->createUserWithCapabilities($school, ['guardians.manage', 'hr.employees.documents.manage']);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::guardian($guardian->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
                $actor,
            ));
            $this->fail('Expected DocumentOwnerTypeNotSupportedException.');
        } catch (DocumentOwnerTypeNotSupportedException $e) {
            $this->assertSame('guardian', $e->ownerType);
        }

        $this->assertSame(0, $this->documentCount($school));
    }

    #[Test]
    public function a_nonexistent_employee_owner_is_rejected_safely(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(DocumentOwnerNotFoundException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee((string) new UuidV7), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function a_cross_school_employee_owner_is_rejected_identically_to_nonexistent(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.manage']);

        $this->expectException(DocumentOwnerNotFoundException::class);

        app(TenantContext::class)->withSchool($schoolA, fn () => $this->service()->create(
            $schoolA,
            new CreateDocumentData(DocumentOwner::employee($employeeB->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function same_user_multiple_schools_capability_does_not_bleed_across_schools(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.manage']);
        // Ordinary membership in School B, no capability there.
        $this->createMembership($actor, $schoolB);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($schoolB, fn () => $this->service()->create(
            $schoolB,
            new CreateDocumentData(DocumentOwner::employee($employeeB->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function rls_and_authorization_are_independent_layers(): void
    {
        // (A) same-School DB-visible owner, actor lacks capability -> denied.
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $noCapabilityActor = $this->createUserWithCapabilities($school, []);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
                $noCapabilityActor,
            ));
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // (B) actor has capability in School A, owner belongs to School B -> not-found, never reaches authorization's "yes" path.
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $capableInA = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(DocumentOwnerNotFoundException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employeeB->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $capableInA,
        ));
    }

    #[Test]
    public function missing_classification_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(InvalidDocumentClassificationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), '', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function an_invalid_classification_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(InvalidDocumentClassificationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'top_secret', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function every_canonical_classification_tier_is_individually_creatable_by_the_correct_capability(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $ordinaryActor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $sensitiveActor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);

        foreach (['public', 'internal', 'sensitive'] as $tier) {
            $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->create("{$tier}.pdf", 10, 'application/pdf')),
                $ordinaryActor,
            ));
            $this->assertSame($tier, $document->classification_tier);
        }

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'highly_sensitive', UploadedFile::fake()->create('hs.pdf', 10, 'application/pdf')),
            $sensitiveActor,
        ));
        $this->assertSame('highly_sensitive', $document->classification_tier);
    }

    #[Test]
    public function a_disallowed_file_type_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(DocumentTypeNotAllowedException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('shell.php', 5, 'application/x-php')),
            $actor,
        ));
    }

    #[Test]
    public function a_sniffed_mime_type_that_does_not_match_the_declared_extension_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(DocumentTypeNotAllowedException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('totally-a-report.exe', 5, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function svg_is_never_accepted(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(DocumentTypeNotAllowedException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')),
            $actor,
        ));
    }

    #[Test]
    public function a_file_exceeding_the_configured_size_limit_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->expectException(DocumentTooLargeException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('big.pdf', 20_000, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function a_dangerous_or_path_traversal_style_filename_is_sanitized_to_a_safe_display_name(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create("../../etc/passwd\x01evil.pdf", 5, 'application/pdf')),
            $actor,
        ));

        $this->assertStringNotContainsString('/', $document->original_filename);
        $this->assertStringNotContainsString("\x01", $document->original_filename);
        $this->assertStringNotContainsString('..', $document->original_filename);
    }

    #[Test]
    public function a_storage_write_failure_never_creates_a_document_row(): void
    {
        Config::set('documents.disk', 'nonexistent_disk_for_test');

        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
                $actor,
            ));
            $this->fail('Expected DocumentStorageException.');
        } catch (DocumentStorageException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->documentCount($school));
        $this->assertSame(0, $this->auditCount($school, 'document.created'));
    }

    #[Test]
    public function two_uploads_with_the_same_original_filename_produce_distinct_object_keys_and_rows(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $first = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')),
            $actor,
        ));
        $second = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')),
            $actor,
        ));

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->storage_path, $second->storage_path);
        $this->assertSame('report.pdf', $first->original_filename);
        $this->assertSame('report.pdf', $second->original_filename);
        Storage::disk('local')->assertExists($first->storage_path);
        Storage::disk('local')->assertExists($second->storage_path);
        $this->assertSame(2, $this->documentCount($school));
    }

    #[Test]
    public function cross_school_object_keys_never_collide(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $actorA = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.manage']);
        $actorB = $this->createUserWithCapabilities($schoolB, ['hr.employees.documents.manage']);

        $documentA = app(TenantContext::class)->withSchool($schoolA, fn () => $this->service()->create(
            $schoolA,
            new CreateDocumentData(DocumentOwner::employee($employeeA->id), 'internal', UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')),
            $actorA,
        ));
        $documentB = app(TenantContext::class)->withSchool($schoolB, fn () => $this->service()->create(
            $schoolB,
            new CreateDocumentData(DocumentOwner::employee($employeeB->id), 'internal', UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')),
            $actorB,
        ));

        $this->assertStringStartsWith("schools/{$schoolA->id}/", $documentA->storage_path);
        $this->assertStringStartsWith("schools/{$schoolB->id}/", $documentB->storage_path);
        $this->assertNotSame($documentA->storage_path, $documentB->storage_path);
    }

    #[Test]
    public function server_derived_metadata_cannot_be_overridden_by_the_caller(): void
    {
        // CreateDocumentData has no size_bytes/mime_type/storage_path/
        // storage_disk/uploaded_by_user_id/status fields at all -- the
        // type system itself is the proof there is no caller override
        // path, verified here by confirming the persisted values match
        // what Laravel's UploadedFile actually reports, not anything a
        // caller could have separately claimed.
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $file = UploadedFile::fake()->create('report.pdf', 42, 'application/pdf');

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', $file),
            $actor,
        ));

        $this->assertSame($file->getSize(), $document->size_bytes);
        $this->assertSame($actor->id, $document->uploaded_by_user_id);
        $this->assertSame($school->id, $document->school_id);
        $this->assertSame('active', $document->status);
    }

    #[Test]
    public function a_successful_create_produces_exactly_one_audit_event_with_no_storage_path(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.created')->where('subject_id', $document->id)->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertArrayNotHasKey('storagePath', $event->metadata);
        $this->assertArrayNotHasKey('storage_path', $event->metadata);
        $this->assertSame(1, $this->auditCount($school, 'document.created'));
    }
}
