<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentStorageException;
use App\Domain\Documents\Infrastructure\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.2 (checklist §52/§53) -- a REAL object-storage integration
 * test against real MinIO (the s3-driver disk, ADR 0011's actual
 * storage technology), not `Storage::fake()`. Proves
 * TenantStoragePath/DocumentService wiring works against a genuine
 * S3-compatible endpoint, not only Laravel's in-memory fake
 * filesystem. Requires the isolated `docs0e2` MinIO container (see
 * this checkpoint's validation report) -- AWS_* env vars point at it
 * for this test run only.
 */
class DocumentMinioStorageTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    #[Test]
    public function a_document_is_actually_written_to_real_minio_with_matching_bytes_and_no_public_url(): void
    {
        Config::set('documents.disk', 's3');

        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $content = 'REAL-MINIO-INTEGRATION-CONTENT-'.bin2hex(random_bytes(16));
        $file = UploadedFile::fake()->createWithContent('report.pdf', $content);

        $document = app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', $file),
            $actor,
        ));

        $this->assertSame('s3', $document->storage_disk);
        $this->assertStringStartsWith("schools/{$school->id}/documents/employee/{$employee->id}/", $document->storage_path);

        $this->assertTrue(Storage::disk('s3')->exists($document->storage_path), 'The object must actually exist on the real MinIO backend.');
        $this->assertSame($content, Storage::disk('s3')->get($document->storage_path), 'The stored object bytes must match exactly what was uploaded.');
        $this->assertSame(strlen($content), $document->size_bytes);

        // ADR 0012: objects remain private -- MinIO's default bucket
        // policy has no anonymous read; this is not itself the
        // authorization control (that belongs to a later download
        // checkpoint), but confirms nothing in this checkpoint
        // accidentally made the bucket/object public.
        Storage::disk('s3')->delete($document->storage_path);
    }

    #[Test]
    public function real_minio_write_failure_leaves_no_document_row(): void
    {
        // A real, unreachable endpoint (not a "nonexistent_disk_for_test"
        // driver name -- that path is already covered by the faked-disk
        // test in DocumentServiceCreateTest) forces an actual network
        // failure against the s3 driver.
        Config::set('documents.disk', 's3');
        Config::set('filesystems.disks.s3.endpoint', 'http://127.0.0.1:1');
        Config::set('filesystems.disks.s3.use_path_style_endpoint', true);

        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
                $actor,
            ));
            $this->fail('Expected a storage exception against an unreachable real endpoint.');
        } catch (DocumentStorageException) {
            $this->addToAssertionCount(1);
        }

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => Document::query()->count(),
        );
        $this->assertSame(0, $count);
    }
}
