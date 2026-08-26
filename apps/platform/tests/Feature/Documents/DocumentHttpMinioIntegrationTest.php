<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Infrastructure\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.5 -- REQUIRED full real-HTTP chain against real MinIO (the
 * s3-driver disk, ADR 0011's actual storage technology), mirroring
 * `DocumentMinioStorageTest`'s/`DocumentReadMinioIntegrationTest`'s
 * established "no Storage::fake()" pattern. Proves the whole HTTP
 * transport -- upload -> list -> metadata -> content -> archive --
 * composes the existing Application services correctly end to end,
 * not merely against the in-memory fake filesystem. Requires the
 * isolated `docs0e5` MinIO container; AWS_* env vars point at it for
 * this test run only.
 */
class DocumentHttpMinioIntegrationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function upload_list_metadata_content_and_archive_compose_correctly_over_real_http_and_real_minio(): void
    {
        Config::set('documents.disk', 's3');

        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        $content = 'REAL-MINIO-HTTP-CHAIN-'.bin2hex(random_bytes(16));

        // 1. Upload.
        $uploadResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent('minio-chain.pdf', $content),
                'classification_tier' => 'internal',
            ])
            ->assertCreated();

        $documentId = $uploadResponse->json('data.document_id');
        $this->assertNotEmpty($documentId);

        app(TenantContext::class)->set($school);
        $storedPath = Document::query()->find($documentId)->storage_path;
        $this->assertTrue(Storage::disk('s3')->exists($storedPath), 'The object must actually exist on the real MinIO backend.');
        $this->assertSame($content, Storage::disk('s3')->get($storedPath));

        // 2. List.
        $listResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertOk();
        $this->assertSame(1, $listResponse->json('meta.total'));
        $this->assertSame($documentId, $listResponse->json('data.0.document_id'));

        // 3. Metadata.
        $metadataResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/documents/{$documentId}")
            ->assertOk();
        $this->assertSame('minio-chain.pdf', $metadataResponse->json('data.original_filename'));

        // 4. Content -- exact bytes, from the real MinIO object.
        $contentResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/v1/schools/{$school->id}/documents/{$documentId}/content")
            ->assertOk();
        $this->assertSame($content, $contentResponse->streamedContent());
        $this->assertStringContainsString('private', strtolower((string) $contentResponse->headers->get('Cache-Control')));
        $this->assertStringContainsString('no-store', strtolower((string) $contentResponse->headers->get('Cache-Control')));

        // 5. Archive -- status flips, object is NOT deleted.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/schools/{$school->id}/documents/{$documentId}/archive")
            ->assertNoContent();

        $this->assertTrue(Storage::disk('s3')->exists($storedPath), 'Archiving must never delete the underlying object.');

        // 6. Archived Documents remain readable (0E.3/0E.4 established policy).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/documents/{$documentId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        Storage::disk('s3')->delete($storedPath);
    }

    #[Test]
    public function highly_sensitive_end_to_end_flow_over_real_minio_is_denied_for_ordinary_capability_and_allowed_for_sensitive(): void
    {
        Config::set('documents.disk', 's3');

        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $sensitiveActor = $this->fullHrActor($school);
        $ordinaryActor = $this->createUserWithCapabilities($school, ['hr.employees.documents.view', 'hr.employees.documents.manage']);

        $content = 'REAL-MINIO-SENSITIVE-'.bin2hex(random_bytes(16));

        $uploadResponse = $this->withHeader('Authorization', 'Bearer '.$this->token($sensitiveActor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent('secret.pdf', $content),
                'classification_tier' => 'highly_sensitive',
            ])
            ->assertCreated();
        $documentId = $uploadResponse->json('data.document_id');

        // Sanctum's guard caches the resolved user for the process
        // lifetime of a single test method -- every switch to a
        // different bearer-token actor below is preceded by
        // Auth::forgetGuards() (HrEmployeeApiRateLimitTest's own
        // established fix for the identical issue).
        Auth::forgetGuards();

        // Ordinary-capability actor: denied on every read surface, no leak.
        $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryActor))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$documentId}")
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryActor))
            ->get("/api/v1/schools/{$school->id}/documents/{$documentId}/content")
            ->assertForbidden();

        $ordinaryListResponse = $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertOk();
        $this->assertSame(0, $ordinaryListResponse->json('meta.total'));

        // Sensitive-capability actor: allowed end to end, exact bytes.
        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token($sensitiveActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $contentResponse = $this->withHeader('Authorization', 'Bearer '.$this->token($sensitiveActor))
            ->get("/api/v1/schools/{$school->id}/documents/{$documentId}/content")
            ->assertOk();
        $this->assertSame($content, $contentResponse->streamedContent());

        app(TenantContext::class)->set($school);
        Storage::disk('s3')->delete(Document::query()->find($documentId)->storage_path);
    }
}
