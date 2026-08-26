<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentContentUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.3 (checklist §49/§50) -- a REAL end-to-end round trip
 * against real MinIO (not `Storage::fake()`): create a Document
 * through the accepted 0E.2 write path, then read it back through the
 * new 0E.3 read path, proving the whole chain -- write, real object
 * storage, TenantStoragePath, and now readStream() -- works against a
 * genuine S3-compatible endpoint.
 */
class DocumentReadMinioIntegrationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function writeService(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function readService(): DocumentReadService
    {
        return app(DocumentReadService::class);
    }

    #[Test]
    public function a_document_written_to_real_minio_is_read_back_with_matching_bytes(): void
    {
        Config::set('documents.disk', 's3');

        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);

        $expected = 'REAL-MINIO-READ-INTEGRATION-'.bin2hex(random_bytes(16));
        $file = UploadedFile::fake()->createWithContent('report.pdf', $expected);

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', $file),
            $actor,
        ));

        $content = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));

        $this->assertSame('s3', $document->storage_disk);
        $this->assertIsResource($content->stream);

        $bytes = stream_get_contents($content->stream);
        fclose($content->stream);

        $this->assertSame($expected, $bytes);
        $this->assertSame(strlen($expected), $content->sizeBytes);

        Storage::disk('s3')->delete($document->storage_path);
    }

    #[Test]
    public function real_minio_read_failure_after_authorization_produces_a_safe_exception(): void
    {
        Config::set('documents.disk', 's3');

        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));

        // Delete the real object directly from MinIO after the Document
        // row was created, simulating exactly the "metadata survives,
        // physical object does not" operational-failure scenario this
        // checkpoint's own contract describes.
        Storage::disk('s3')->delete($document->storage_path);

        $this->expectException(DocumentContentUnavailableException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->readService()->content($school, $document->id, $actor));
    }
}
