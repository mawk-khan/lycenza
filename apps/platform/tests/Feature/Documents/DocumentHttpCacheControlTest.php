<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.5 -- REQUIRED Cache-Control proof (gates 39/40/82), mirroring
 * `Tests\Feature\HR\HrEmployeeApiCacheControlTest`'s established
 * pattern: every Documents response -- success, 403, 404 -- carries
 * `Cache-Control: private, no-store`, and never a contradictory
 * public/shared directive.
 */
class DocumentHttpCacheControlTest extends TestCase
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

    private function assertPrivateNoStore(TestResponse $response): void
    {
        $header = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('no-store', $header);
        $this->assertStringNotContainsString('public', $header);
        $this->assertStringNotContainsString('max-age', $header);
    }

    #[Test]
    public function upload_response_is_private_no_store(): void
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

        $this->assertPrivateNoStore($response);
    }

    #[Test]
    public function ordinary_list_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertOk();

        $this->assertPrivateNoStore($response);
    }

    #[Test]
    public function sensitive_list_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertOk();

        $this->assertPrivateNoStore($response);
    }

    #[Test]
    public function metadata_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertOk();

        $this->assertPrivateNoStore($response);
    }

    #[Test]
    public function content_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")
            ->assertOk();

        $this->assertPrivateNoStore($response);
    }

    #[Test]
    public function archive_response_is_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = $this->createDocument($school, $employee, $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->post("/api/v1/schools/{$school->id}/documents/{$document->id}/archive")
            ->assertNoContent();

        $this->assertPrivateNoStore($response);
    }

    #[Test]
    public function a_forbidden_metadata_response_still_carries_private_no_store(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->fullHrActor($school);
        $denied = $this->createUserWithCapabilities($school, []);
        $document = $this->createDocument($school, $employee, $writer);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($denied))
            ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
            ->assertForbidden();

        $this->assertPrivateNoStore($response);
    }

    #[Test]
    public function a_not_found_metadata_response_still_carries_private_no_store(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/documents/not-a-uuid")
            ->assertNotFound();

        $this->assertPrivateNoStore($response);
    }
}
