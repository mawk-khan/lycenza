<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.5 -- REQUIRED HTTP contract proof for
 * `GET .../employees/{employee}/documents` and
 * `.../documents/sensitive`, preserving 0E.4's own visible-total-only
 * pagination guarantee exactly over real HTTP.
 */
class DocumentHttpListingTest extends TestCase
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

    private function seedDocument($school, $employee, $actor, string $tier): void
    {
        app(TenantContext::class)->withSchool($school, fn () => app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function ordinary_listing_returns_the_established_pagination_shape(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $this->seedDocument($school, $employee, $actor, 'internal');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertOk();

        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        $this->assertSame(['page', 'perPage', 'total'], array_keys($response->json('meta')));
        $this->assertSame(1, $response->json('meta.total'));
        $entry = $response->json('data.0');
        $this->assertSame([
            'document_id', 'owner_type', 'owner_id', 'classification_tier',
            'status', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_at',
        ], array_keys($entry));
    }

    #[Test]
    public function highly_sensitive_documents_are_completely_hidden_from_the_ordinary_http_listing(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        for ($i = 0; $i < 20; $i++) {
            $this->seedDocument($school, $employee, $actor, 'internal');
        }
        for ($i = 0; $i < 30; $i++) {
            $this->seedDocument($school, $employee, $actor, 'highly_sensitive');
        }

        $token = $this->token($actor);

        $page1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents?per_page=10&page=1")
            ->assertOk();
        $this->assertCount(10, $page1->json('data'));
        $this->assertSame(20, $page1->json('meta.total'));

        $page2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents?per_page=10&page=2")
            ->assertOk();
        $this->assertCount(10, $page2->json('data'));
        $this->assertSame(20, $page2->json('meta.total'));

        $page3 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents?per_page=10&page=3")
            ->assertOk();
        $this->assertSame([], $page3->json('data'));
        $this->assertSame(20, $page3->json('meta.total'));

        $raw = $page1->getContent().$page2->getContent().$page3->getContent();
        $this->assertStringNotContainsString('highly_sensitive', $raw);
    }

    #[Test]
    public function sensitive_endpoint_returns_only_highly_sensitive_documents(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $this->seedDocument($school, $employee, $actor, 'internal');
        $this->seedDocument($school, $employee, $actor, 'highly_sensitive');
        $this->seedDocument($school, $employee, $actor, 'highly_sensitive');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        foreach ($response->json('data') as $entry) {
            $this->assertSame('highly_sensitive', $entry['classification_tier']);
        }
    }

    #[Test]
    public function documents_view_alone_is_denied_the_sensitive_endpoint(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertForbidden();
    }

    #[Test]
    public function a_non_empty_sensitive_listing_is_audited_exactly_once_with_no_controller_duplicate(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $this->seedDocument($school, $employee, $actor, 'highly_sensitive');
        $this->seedDocument($school, $employee, $actor, 'highly_sensitive');
        $this->seedDocument($school, $employee, $actor, 'highly_sensitive');

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertOk();

        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->count());
    }

    #[Test]
    public function an_empty_sensitive_listing_is_not_audited(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertOk();

        app(TenantContext::class)->set($school);
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->count());
    }

    #[Test]
    public function a_denied_sensitive_listing_is_not_audited(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->fullHrActor($school);
        $denied = $this->createUserWithCapabilities($school, []);
        $this->seedDocument($school, $employee, $writer, 'highly_sensitive');

        $this->withHeader('Authorization', 'Bearer '.$this->token($denied))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertForbidden();

        app(TenantContext::class)->set($school);
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->count());
    }

    #[Test]
    public function a_cross_school_employee_is_a_safe_404_on_both_listing_endpoints(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $actorA = $this->fullHrActor($schoolA);
        $token = $this->token($actorA);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeB->id}/documents")
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeB->id}/documents/sensitive")
            ->assertNotFound();
    }

    #[Test]
    public function a_malformed_employee_uuid_is_a_safe_404(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/not-a-uuid/documents")
            ->assertNotFound();
    }

    #[Test]
    public function unauthenticated_listing_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertUnauthorized();
    }
}
