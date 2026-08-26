<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.5 -- REQUIRED HTTP contract proof for
 * `POST /documents/{document}/archive`, a thin adapter over
 * `DocumentService::archive()`. Never a hard delete, never an object
 * deletion, never a classification/owner change.
 */
class DocumentHttpArchiveTest extends TestCase
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

    private function createDocument($school, $employee, $actor, string $tier = 'internal'): Document
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->createWithContent('report.pdf', 'bytes')),
            $actor,
        ));
    }

    #[Test]
    public function a_successful_archive_returns_204_and_flips_status_only(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/documents/{$document->id}/archive")
            ->assertNoContent();

        $this->assertSame('', $response->getContent());

        app(TenantContext::class)->set($school);
        $refreshed = Document::query()->find($document->id);
        $this->assertSame('archived', $refreshed->status);
        $this->assertSame($document->classification_tier, $refreshed->classification_tier);
        $this->assertSame($document->employee_id, $refreshed->employee_id);
        $this->assertSame($document->storage_path, $refreshed->storage_path);
        $this->assertTrue(Storage::disk($refreshed->storage_disk)->exists($refreshed->storage_path));
    }

    #[Test]
    public function archive_is_audited_exactly_once_with_no_extra_metadata_read_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/documents/{$document->id}/archive")
            ->assertNoContent();

        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'document.archived')->count());
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_metadata_viewed')->count());
    }

    #[Test]
    public function ordinary_documents_manage_alone_cannot_archive_a_highly_sensitive_document(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->fullHrActor($school);
        $ordinaryOnly = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $document = $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        $this->withHeader('Authorization', 'Bearer '.$this->token($ordinaryOnly))
            ->post("/api/v1/schools/{$school->id}/documents/{$document->id}/archive")
            ->assertForbidden();

        app(TenantContext::class)->set($school);
        $this->assertSame('active', Document::query()->find($document->id)->status);
    }

    #[Test]
    public function a_cross_school_document_id_is_a_safe_404(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $writerB = $this->fullHrActor($schoolB);
        $document = $this->createDocument($schoolB, $employeeB, $writerB);
        $actorA = $this->fullHrActor($schoolA);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->post("/api/v1/schools/{$schoolA->id}/documents/{$document->id}/archive")
            ->assertNotFound();

        app(TenantContext::class)->set($schoolB);
        $this->assertSame('active', Document::query()->find($document->id)->status);
    }

    #[Test]
    public function a_malformed_document_uuid_is_a_safe_404(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/documents/not-a-uuid/archive")
            ->assertNotFound();
    }

    #[Test]
    public function unauthenticated_archive_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor);

        $this->post("/api/v1/schools/{$school->id}/documents/{$document->id}/archive")->assertUnauthorized();
    }
}
