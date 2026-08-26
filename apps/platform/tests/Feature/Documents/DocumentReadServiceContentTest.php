<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentContentUnavailableException;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.3 -- DocumentReadService::content().
 */
class DocumentReadServiceContentTest extends TestCase
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
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->createWithContent('report.pdf', 'exact-content-bytes')),
            $actor,
        ));
    }

    #[Test]
    public function content_opens_a_stream_with_bytes_matching_the_original_upload(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor);

        $content = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));

        $this->assertSame($document->id, $content->documentId);
        $this->assertSame('report.pdf', $content->originalFilename);
        $this->assertSame('application/pdf', $content->mimeType);
        $this->assertIsResource($content->stream);

        $bytes = stream_get_contents($content->stream);
        fclose($content->stream);

        $this->assertSame('exact-content-bytes', $bytes);
    }

    #[Test]
    public function unauthorized_content_access_is_denied_and_touches_no_storage(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $unauthorized = $this->createUserWithCapabilities($school, []);
        $document = $this->createDocument($school, $employee, $owner);

        Storage::shouldReceive('disk')->never();

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $unauthorized));
    }

    #[Test]
    public function ordinary_document_view_alone_cannot_read_highly_sensitive_content(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $ordinaryReader = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        Storage::shouldReceive('disk')->never();

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $ordinaryReader));
    }

    #[Test]
    public function a_sensitive_view_capable_actor_can_read_highly_sensitive_content(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $sensitiveReader = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        $content = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $sensitiveReader));

        $this->assertIsResource($content->stream);
        fclose($content->stream);
    }

    #[Test]
    public function a_missing_object_produces_a_safe_exception_and_no_audit(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view']);
        $document = $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        // Simulates an operational failure/external lifecycle mistake:
        // the metadata row survives, the physical object does not.
        Storage::disk('local')->delete($document->storage_path);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));
            $this->fail('Expected DocumentContentUnavailableException.');
        } catch (DocumentContentUnavailableException) {
            $this->addToAssertionCount(1);
        }

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_content_accessed')->count(),
        );
        $this->assertSame(0, $count);
    }

    #[Test]
    public function a_storage_read_failure_produces_a_safe_exception_not_a_raw_provider_error(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor);

        // A disk name with no configured driver makes Storage::disk()
        // itself throw -- exactly like a real misconfigured/unreachable
        // object-store endpoint would, matching
        // DocumentServiceCreateTest::a_storage_write_failure_never_creates_a_document_row's
        // own established pattern for the write side.
        app(TenantContext::class)->withSchool($school, function () use ($document) {
            $document->forceFill(['storage_disk' => 'nonexistent_disk_for_test'])->saveQuietly();
        });

        $this->expectException(DocumentContentUnavailableException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));
    }

    #[Test]
    public function an_archived_documents_content_remains_readable(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor);

        app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->archive($school, $document, $actor));

        $content = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));

        $this->assertIsResource($content->stream);
        fclose($content->stream);
    }

    #[Test]
    public function ordinary_tier_content_access_is_not_audited(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor, 'internal');

        $content = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));
        fclose($content->stream);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_content_accessed')->count(),
        );
        $this->assertSame(0, $count);
    }

    #[Test]
    public function highly_sensitive_content_access_is_audited_exactly_once_with_no_storage_path(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $reader = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        $content = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $reader));
        fclose($content->stream);

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'document.sensitive_content_accessed')
                ->where('subject_id', $document->id)
                ->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($reader->id, $event->actor_user_id);
        $this->assertArrayNotHasKey('storagePath', $event->metadata);
        $this->assertArrayNotHasKey('storage_path', $event->metadata);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_content_accessed')->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function content_access_involves_no_database_write(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $actor);

        $content = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));
        fclose($content->stream);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => Document::query()->find($document->id));
        $this->assertSame($document->updated_at->toIso8601String(), $fresh->updated_at->toIso8601String());
        $this->assertSame('active', $fresh->status);
    }
}
