<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Application\Exceptions\LearningContentIllegalTransitionException;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\SchoolAuditEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesLearningContentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.2 -- LearningContentService: creation, ordinary field edits
 * at every status, the closed lifecycle transition map, and audit
 * metadata bounds.
 */
class LearningContentServiceTest extends TestCase
{
    use CreatesLearningContentFixtures;

    private function service(): LearningContentService
    {
        return app(LearningContentService::class);
    }

    // --- creation ------------------------------------------------------

    #[Test]
    public function creation_always_starts_draft(): void
    {
        $w = $this->learningContentWorld();

        $content = $this->service()->create($w['school'], $w['offering']->id, [
            'title' => 'Chapter 1 reading', 'description' => 'A short note.',
        ], $w['actor']);

        $this->assertSame(LearningContent::STATUS_DRAFT, $content->status);
        $this->assertSame($w['offering']->id, $content->subject_offering_id);
        $this->assertSame(0, $content->sequence, 'sequence defaults to 0 when omitted.');
    }

    #[Test]
    public function creation_against_another_schools_offering_fails(): void
    {
        $w = $this->learningContentWorld();
        $other = $this->learningContentWorld();

        $this->expectException(ModelNotFoundException::class);

        $this->service()->create($w['school'], $other['offering']->id, ['title' => 'X'], $w['actor']);
    }

    // --- editing at every status -----------------------------------------

    #[Test]
    public function ordinary_fields_are_editable_while_draft(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_DRAFT]);

        $updated = $this->service()->update($w['school'], $content, ['title' => 'Revised title'], $w['actor']);

        $this->assertSame('Revised title', $updated->title);
        $this->assertSame(LearningContent::STATUS_DRAFT, $updated->status, 'update() never changes status.');
    }

    #[Test]
    public function ordinary_fields_remain_editable_once_published(): void
    {
        // No frozen-after-publish rule -- matches Examination/
        // ExaminationPaper/SyllabusUnit precedent exactly.
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_PUBLISHED]);

        $updated = $this->service()->update($w['school'], $content, ['title' => 'Corrected title'], $w['actor']);

        $this->assertSame('Corrected title', $updated->title);
        $this->assertSame(LearningContent::STATUS_PUBLISHED, $updated->status);
    }

    #[Test]
    public function ordinary_fields_remain_editable_once_archived(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_ARCHIVED]);

        $updated = $this->service()->update($w['school'], $content, ['description' => 'Corrected note'], $w['actor']);

        $this->assertSame('Corrected note', $updated->description);
        $this->assertSame(LearningContent::STATUS_ARCHIVED, $updated->status);
    }

    #[Test]
    public function an_empty_update_is_a_safe_no_op(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['title' => 'Unchanged']);

        $result = $this->service()->update($w['school'], $content, [], $w['actor']);

        $this->assertSame('Unchanged', $result->title);
    }

    // --- lifecycle: legal transitions -------------------------------------

    #[Test]
    public function draft_can_be_published(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_DRAFT]);

        $published = $this->service()->publish($w['school'], $content, $w['actor']);

        $this->assertSame(LearningContent::STATUS_PUBLISHED, $published->status);
    }

    #[Test]
    public function published_can_be_archived(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_PUBLISHED]);

        $archived = $this->service()->archive($w['school'], $content, $w['actor']);

        $this->assertSame(LearningContent::STATUS_ARCHIVED, $archived->status);
    }

    #[Test]
    public function archived_can_be_republished(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_ARCHIVED]);

        $republished = $this->service()->publish($w['school'], $content, $w['actor']);

        $this->assertSame(LearningContent::STATUS_PUBLISHED, $republished->status);
    }

    // --- lifecycle: illegal transitions ------------------------------------

    #[Test]
    public function publishing_already_published_content_is_rejected(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_PUBLISHED]);

        $this->expectException(LearningContentIllegalTransitionException::class);

        $this->service()->publish($w['school'], $content, $w['actor']);
    }

    #[Test]
    public function archiving_draft_content_is_rejected(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_DRAFT]);

        $this->expectException(LearningContentIllegalTransitionException::class);

        $this->service()->archive($w['school'], $content, $w['actor']);
    }

    #[Test]
    public function archiving_already_archived_content_is_rejected(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_ARCHIVED]);

        $this->expectException(LearningContentIllegalTransitionException::class);

        $this->service()->archive($w['school'], $content, $w['actor']);
    }

    // --- audit -----------------------------------------------------------

    #[Test]
    public function creation_publication_and_archival_are_audited_with_bounded_metadata(): void
    {
        $w = $this->learningContentWorld();

        $content = $this->service()->create($w['school'], $w['offering']->id, [
            'title' => 'Secret Instructional Title', 'description' => 'Secret note body',
        ], $w['actor']);

        $created = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.created')->firstOrFail());
        $this->assertSame($content->id, $created->metadata['learningContentId']);
        $this->assertSame($w['offering']->id, $created->metadata['subjectOfferingId']);
        $this->assertStringNotContainsString('Secret Instructional Title', json_encode($created->metadata));
        $this->assertStringNotContainsString('Secret note body', json_encode($created->metadata));

        $content = $this->service()->publish($w['school'], $content, $w['actor']);
        $published = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.published')->firstOrFail());
        $this->assertSame('draft', $published->metadata['previousStatus']);
        $this->assertSame('published', $published->metadata['newStatus']);

        $this->service()->archive($w['school'], $content, $w['actor']);
        $archived = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.archived')->firstOrFail());
        $this->assertSame('published', $archived->metadata['previousStatus']);
        $this->assertSame('archived', $archived->metadata['newStatus']);
    }

    #[Test]
    public function an_ordinary_update_never_leaks_title_or_description_values_into_audit(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);

        $this->service()->update($w['school'], $content, [
            'title' => 'A Very Secret Title', 'description' => 'A Very Secret Description',
        ], $w['actor']);

        $updated = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.updated')->firstOrFail());

        $this->assertContains('title', $updated->metadata['changedFields']);
        $this->assertContains('description', $updated->metadata['changedFields']);
        $encoded = json_encode($updated->metadata);
        $this->assertStringNotContainsString('A Very Secret Title', $encoded);
        $this->assertStringNotContainsString('A Very Secret Description', $encoded);
    }
}
