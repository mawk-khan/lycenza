<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.5 -- REQUIRED HTTP contract proof for
 * `GET /documents/{document}` and `/documents/{document}/content`, the
 * direct by-id transport over `DocumentReadService`. Central invariant
 * under test: knowing a Document UUID must never be sufficient to
 * access its metadata or bytes over HTTP, and a Highly Sensitive
 * Document's existence/classification/filename must never leak to an
 * unauthorized caller.
 */
class DocumentHttpDirectAccessTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function createDocument($school, $employee, $actor, string $tier, string $filename = 'report.pdf', string $content = 'exact-content-bytes'): Document
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->createWithContent($filename, $content)),
            $actor,
        ));
    }

    #[Test]
    public function metadata_returns_the_safe_dto_shape(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor, 'internal');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertOk();

        $entry = $response->json('data');
        $this->assertSame([
            'document_id', 'owner_type', 'owner_id', 'classification_tier',
            'status', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_at',
        ], array_keys($entry));
        $this->assertSame($document->id, $entry['document_id']);
    }

    #[Test]
    public function content_streams_the_exact_bytes_with_safe_headers(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor, 'internal', 'report.pdf', 'exact-content-bytes');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")
            ->assertOk();

        $this->assertSame('exact-content-bytes', $response->streamedContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('report.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen('exact-content-bytes'), $response->headers->get('Content-Length'));
    }

    #[Test]
    public function content_disposition_is_safe_for_unusual_filenames(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        // sanitizeDisplayName() strips path separators/control chars at
        // write time (0E.2) -- this proves whatever survives that
        // (spaces, quotes, Unicode) still produces a syntactically safe
        // header with no CRLF injection.
        $document = $this->createDocument($school, $employee, $actor, 'internal', 'my "résumé" file.pdf', 'bytes');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")
            ->assertOk();

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }

    #[Test]
    public function highly_sensitive_metadata_is_audited_exactly_once_with_no_controller_duplicate(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertOk();

        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_metadata_viewed')->count());
    }

    #[Test]
    public function highly_sensitive_content_is_audited_exactly_once(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")
            ->assertOk();

        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_content_accessed')->count());
    }

    #[Test]
    public function ordinary_document_view_alone_cannot_read_a_highly_sensitive_document_metadata(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->fullHrActor($school);
        $ordinary = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive', 'secret.pdf');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($ordinary))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertForbidden();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('secret.pdf', $raw);
        $this->assertStringNotContainsString('highly_sensitive', $raw);
    }

    #[Test]
    public function ordinary_document_view_alone_cannot_read_a_highly_sensitive_document_content(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->fullHrActor($school);
        $ordinary = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive', 'secret.pdf', 'top-secret-bytes');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($ordinary))
            ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")
            ->assertForbidden();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('top-secret-bytes', $raw);
        $this->assertStringNotContainsString('secret.pdf', $raw);

        app(TenantContext::class)->set($school);
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_content_accessed')->count());
    }

    #[Test]
    public function a_cross_school_document_id_is_a_safe_404_on_both_routes(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $writerB = $this->fullHrActor($schoolB);
        $document = $this->createDocument($schoolB, $employeeB, $writerB, 'internal');
        $actorA = $this->fullHrActor($schoolA);
        $token = $this->token($actorA);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolA->id}/documents/{$document->id}")
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/v1/schools/{$schoolA->id}/documents/{$document->id}/content")
            ->assertNotFound();
    }

    #[Test]
    public function a_malformed_document_uuid_is_a_safe_404_not_a_raw_sql_error(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        $metadataResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/documents/not-a-uuid")
            ->assertNotFound();
        $this->assertStringNotContainsString('invalid input syntax', $metadataResponse->getContent());

        $contentResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/v1/schools/{$school->id}/documents/not-a-uuid/content")
            ->assertNotFound();
        $this->assertStringNotContainsString('invalid input syntax', $contentResponse->getContent());
    }

    #[Test]
    public function a_public_classification_document_still_requires_authentication_and_authorization(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $writer, 'public');

        $this->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertUnauthorized();

        $denied = $this->createUserWithCapabilities($school, []);
        $this->withHeader('Authorization', 'Bearer '.$this->token($denied))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertForbidden();

        // Sanctum's guard caches the resolved user for the process
        // lifetime of a single test method -- switching bearer tokens
        // to a different actor mid-test requires forgetting the guard
        // first (the same fix HrEmployeeApiRateLimitTest's own
        // `different_users_in_the_same_school_have_independent_buckets`
        // test already established).
        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token($writer))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertOk();
    }

    #[Test]
    public function archived_documents_remain_readable(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor, 'internal');

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/documents/{$document->id}/archive")
            ->assertNoContent();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertOk();

        $this->assertSame('archived', $response->json('data.status'));
    }

    #[Test]
    public function unauthenticated_direct_access_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor, 'internal');

        $this->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")->assertUnauthorized();
        $this->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")->assertUnauthorized();
    }
}
