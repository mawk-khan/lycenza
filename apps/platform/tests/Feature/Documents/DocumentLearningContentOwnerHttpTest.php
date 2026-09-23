<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Infrastructure\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesLearningContentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.2 -- HTTP contract proof for the `learning_content` Documents
 * owner arm (ADR 0039 decision 8): `POST`/`GET
 * /api/v1/schools/{school}/learning-content/{learningContent}/documents`.
 * Mirrors Tests\Feature\Documents\DocumentHttpUploadTest's exact
 * transport-proof shape for the Employee owner type, adapted for the
 * fixed-`internal`-tier, no-`classification_tier`-field contract this
 * owner type uses.
 */
class DocumentLearningContentOwnerHttpTest extends TestCase
{
    use CreatesLearningContentFixtures;

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
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->post("/api/v1/schools/{$w['school']->id}/learning-content/{$content->id}/documents", [
                'file' => UploadedFile::fake()->create('handout.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        $entry = $response->json('data');
        $this->assertSame('learning_content', $entry['owner_type']);
        $this->assertSame($content->id, $entry['owner_id']);
        $this->assertSame('internal', $entry['classification_tier']);

        app(TenantContext::class)->set($w['school']);
        $this->assertSame(1, Document::query()->count());
    }

    #[Test]
    public function a_classification_tier_field_is_not_accepted_and_never_overrides_internal(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->post("/api/v1/schools/{$w['school']->id}/learning-content/{$content->id}/documents", [
                'file' => UploadedFile::fake()->create('handout.pdf', 10, 'application/pdf'),
                'classification_tier' => 'highly_sensitive',
            ])
            ->assertCreated();

        // The extra field is silently ignored -- the tier is fixed
        // server-side, never taken from the request.
        $this->assertSame('internal', $response->json('data.classification_tier'));
    }

    #[Test]
    public function an_actor_without_lms_content_manage_is_forbidden(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['lms.content.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($viewer))
            ->post("/api/v1/schools/{$w['school']->id}/learning-content/{$content->id}/documents", [
                'file' => UploadedFile::fake()->create('handout.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function the_index_lists_only_documents_for_that_learning_content(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $other = $this->createLearningContent($w['offering']);

        $this->createDocumentForLearningContent($content);
        $this->createDocumentForLearningContent($content);
        $this->createDocumentForLearningContent($other);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->get("/api/v1/schools/{$w['school']->id}/learning-content/{$content->id}/documents")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        foreach ($response->json('data') as $entry) {
            $this->assertSame($content->id, $entry['owner_id']);
        }
    }

    #[Test]
    public function the_generic_show_and_archive_routes_already_work_for_this_owner_type(): void
    {
        // Proves the extension truly reused the existing generic-by-id
        // Document routes -- no new show/archive route was built for
        // this owner type.
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $document = $this->createDocumentForLearningContent($content);

        $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->get("/api/v1/schools/{$w['school']->id}/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.owner_type', 'learning_content');

        $this->withHeader('Authorization', 'Bearer '.$this->token($w['actor']))
            ->post("/api/v1/schools/{$w['school']->id}/documents/{$document->id}/archive")
            ->assertNoContent();

        app(TenantContext::class)->set($w['school']);
        $this->assertSame('archived', $document->fresh()->status);
    }
}
