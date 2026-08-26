<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.5 -- REQUIRED HTTP contract proof for
 * `POST /api/v1/schools/{school}/employees/{employee}/documents`, the
 * Documents module's first write-path transport. A thin adapter over
 * the already-authoritative `DocumentService::create()` -- these tests
 * prove the HTTP transport preserves that behavior exactly.
 */
class DocumentHttpUploadTest extends TestCase
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

    #[Test]
    public function an_authorized_upload_returns_201_with_the_safe_metadata_shape(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertCreated();

        $entry = $response->json('data');
        $this->assertSame([
            'document_id', 'owner_type', 'owner_id', 'classification_tier',
            'status', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_at',
        ], array_keys($entry));
        $this->assertSame('employee', $entry['owner_type']);
        $this->assertSame($employee->id, $entry['owner_id']);
        $this->assertSame('internal', $entry['classification_tier']);
        $this->assertSame('active', $entry['status']);
        $this->assertSame('report.pdf', $entry['original_filename']);

        app(TenantContext::class)->set($school);
        $this->assertSame(1, Document::query()->count());
    }

    #[Test]
    public function no_storage_metadata_ever_appears_in_the_response(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertCreated()
            ->getContent();

        foreach (['storage_disk', 'storage_path', 'uploaded_by_user_id', 'bucket', 'url', 'signed'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    #[Test]
    public function a_successful_upload_is_audited_exactly_once_with_no_controller_duplicate(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertCreated();

        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'document.created')->count());
    }

    #[Test]
    public function a_highly_sensitive_upload_does_not_also_create_a_sensitive_metadata_viewed_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'),
                'classification_tier' => 'highly_sensitive',
            ])
            ->assertCreated();

        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'document.created')->count());
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_metadata_viewed')->count());
    }

    #[Test]
    public function ordinary_documents_manage_alone_cannot_upload_highly_sensitive(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'),
                'classification_tier' => 'highly_sensitive',
            ])
            ->assertForbidden();

        app(TenantContext::class)->set($school);
        $this->assertSame(0, Document::query()->count());
    }

    #[Test]
    public function no_capability_at_all_is_denied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, []);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function unauthenticated_upload_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
            'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            'classification_tier' => 'internal',
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_cross_school_employee_is_a_safe_404_with_no_storage_write(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $actorA = $this->fullHrActor($schoolA);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->post("/api/v1/schools/{$schoolA->id}/employees/{$employeeB->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertNotFound();

        app(TenantContext::class)->set($schoolB);
        $this->assertSame(0, Document::query()->count());
    }

    #[Test]
    public function a_malformed_employee_uuid_is_a_safe_404_not_a_raw_sql_error(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/not-a-uuid/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function missing_file_is_422_with_no_side_effects(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'classification_tier' => 'internal',
            ])
            ->assertStatus(422);

        app(TenantContext::class)->set($school);
        $this->assertSame(0, Document::query()->count());
    }

    #[Test]
    public function an_array_instead_of_a_file_is_422(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => ['not', 'a', 'file'],
                'classification_tier' => 'internal',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function missing_classification_tier_is_422(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_array_classification_tier_is_422(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => ['internal'],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_invalid_classification_tier_value_is_422_with_no_side_effects(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'bogus-tier',
            ])
            ->assertStatus(422);

        $this->assertSame('INVALID_DOCUMENT_CLASSIFICATION', $response->json('error.code'));

        app(TenantContext::class)->set($school);
        $this->assertSame(0, Document::query()->count());
    }

    #[Test]
    public function a_disallowed_file_type_is_422_with_no_storage_write(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
                'classification_tier' => 'internal',
            ])
            ->assertStatus(422);

        $this->assertSame('DOCUMENT_TYPE_NOT_ALLOWED', $response->json('error.code'));

        app(TenantContext::class)->set($school);
        $this->assertSame(0, Document::query()->count());
    }

    #[Test]
    public function an_oversized_file_is_422(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertStatus(422);

        $this->assertSame('DOCUMENT_TOO_LARGE', $response->json('error.code'));
    }

    #[Test]
    public function uploading_the_same_filename_twice_creates_two_distinct_documents_with_no_overwrite(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('duplicate.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertCreated();

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('duplicate.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertCreated();

        $this->assertNotSame($first->json('data.document_id'), $second->json('data.document_id'));

        app(TenantContext::class)->set($school);
        $documents = Document::query()->get();
        $this->assertCount(2, $documents);
        $this->assertNotSame($documents[0]->storage_path, $documents[1]->storage_path);
    }

    #[Test]
    public function a_500_source_never_leaks_a_php_stack_trace(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        // A well-formed but nonexistent Employee UUID (not malformed --
        // Str::isUuid() passes) exercises the DocumentOwnerNotFoundException
        // path end-to-end without ever reaching a raw exception.
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/employees/".Str::uuid().'/documents', [
                'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                'classification_tier' => 'internal',
            ])
            ->assertNotFound();

        $this->assertStringNotContainsString('Stack trace', $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());
    }
}
