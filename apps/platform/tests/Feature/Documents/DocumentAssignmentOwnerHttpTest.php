<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Infrastructure\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.3 -- HTTP contract proof for the `assignment` Documents
 * owner arm (ADR 0037 decision 8): `POST`/`GET
 * /api/v1/schools/{school}/assignments/{assignment}/documents`. Mirrors
 * Tests\Feature\Documents\DocumentLearningContentOwnerHttpTest's exact
 * transport-proof shape.
 */
class DocumentAssignmentOwnerHttpTest extends TestCase
{
    use CreatesAssignmentFixtures;

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
    public function an_authorized_upload_returns_201_with_a_fixed_internal_tier(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->post("/api/v1/schools/{$w['school']->id}/assignments/{$assignment->id}/documents", [
                'file' => UploadedFile::fake()->create('handout.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        $entry = $response->json('data');
        $this->assertSame('assignment', $entry['owner_type']);
        $this->assertSame($assignment->id, $entry['owner_id']);
        $this->assertSame('internal', $entry['classification_tier']);

        app(TenantContext::class)->set($w['school']);
        $this->assertSame(1, Document::query()->count());
    }

    #[Test]
    public function an_actor_without_lms_assignments_manage_is_forbidden(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['lms.assignments.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($viewer))
            ->post("/api/v1/schools/{$w['school']->id}/assignments/{$assignment->id}/documents", [
                'file' => UploadedFile::fake()->create('handout.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function the_index_lists_only_documents_for_that_assignment(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);
        $other = $this->createAssignment($w['offering']);

        $this->createDocumentForAssignment($assignment);
        $this->createDocumentForAssignment($assignment);
        $this->createDocumentForAssignment($other);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->get("/api/v1/schools/{$w['school']->id}/assignments/{$assignment->id}/documents")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        foreach ($response->json('data') as $entry) {
            $this->assertSame($assignment->id, $entry['owner_id']);
        }
    }

    #[Test]
    public function the_generic_show_and_archive_routes_already_work_for_this_owner_type(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);
        $document = $this->createDocumentForAssignment($assignment);

        $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->get("/api/v1/schools/{$w['school']->id}/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.owner_type', 'assignment');

        $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->post("/api/v1/schools/{$w['school']->id}/documents/{$document->id}/archive")
            ->assertNoContent();

        app(TenantContext::class)->set($w['school']);
        $this->assertSame('archived', $document->fresh()->status);
    }
}
