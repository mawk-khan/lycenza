<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\Exceptions\AssignmentDueDateOutsideAcademicYearException;
use App\Domain\LMS\Application\Exceptions\AssignmentDueDateRequiredException;
use App\Domain\LMS\Application\Exceptions\AssignmentIllegalTransitionException;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\SchoolAuditEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.3 -- AssignmentService: creation, due-date validation, the
 * closed lifecycle transition map, ordinary field edits at every
 * status, and audit metadata bounds.
 */
class AssignmentServiceTest extends TestCase
{
    use CreatesAssignmentFixtures;

    private function service(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    // --- creation ------------------------------------------------------

    #[Test]
    public function creation_always_starts_draft_and_due_date_is_optional(): void
    {
        $w = $this->assignmentWorld();

        $assignment = $this->service()->create($w['school'], $w['offering']->id, [
            'title' => 'Essay on rivers', 'instructions' => 'Write 500 words.',
        ], $w['actor']);

        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->status);
        $this->assertSame($w['offering']->id, $assignment->subject_offering_id);
        $this->assertNull($assignment->due_on);
    }

    #[Test]
    public function a_due_date_within_the_academic_year_is_accepted(): void
    {
        $w = $this->assignmentWorld();

        $assignment = $this->service()->create($w['school'], $w['offering']->id, [
            'title' => 'Essay', 'due_on' => '2026-09-15',
        ], $w['actor']);

        $this->assertSame('2026-09-15', $assignment->due_on->toDateString());
    }

    #[Test]
    public function a_due_date_outside_the_academic_year_is_rejected(): void
    {
        $w = $this->assignmentWorld();

        $this->expectException(AssignmentDueDateOutsideAcademicYearException::class);

        $this->service()->create($w['school'], $w['offering']->id, [
            'title' => 'Essay', 'due_on' => '2025-01-01',
        ], $w['actor']);
    }

    #[Test]
    public function a_future_due_date_is_permitted(): void
    {
        // Matches Examination's own "future dates permitted and
        // expected" precedent -- a due date is scheduled ahead by
        // nature, never restricted to "not in the future".
        $w = $this->assignmentWorld();

        $assignment = $this->service()->create($w['school'], $w['offering']->id, [
            'title' => 'Essay', 'due_on' => '2027-03-01',
        ], $w['actor']);

        $this->assertSame('2027-03-01', $assignment->due_on->toDateString());
    }

    #[Test]
    public function creation_against_another_schools_offering_fails(): void
    {
        $w = $this->assignmentWorld();
        $other = $this->assignmentWorld();

        $this->expectException(ModelNotFoundException::class);

        $this->service()->create($w['school'], $other['offering']->id, ['title' => 'X'], $w['actor']);
    }

    // --- editing at every status -----------------------------------------

    #[Test]
    public function ordinary_fields_are_editable_while_draft(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT]);

        $updated = $this->service()->update($w['school'], $assignment, ['title' => 'Revised title'], $w['actor']);

        $this->assertSame('Revised title', $updated->title);
        $this->assertSame(Assignment::STATUS_DRAFT, $updated->status, 'update() never changes status.');
    }

    #[Test]
    public function due_date_remains_editable_once_published(): void
    {
        // No frozen-after-publish rule -- ADR 0039 §10's explicit
        // "Published Assignments MAY be edited... including the due
        // date."
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], [
            'status' => Assignment::STATUS_PUBLISHED, 'due_on' => '2026-09-01',
        ]);

        $updated = $this->service()->update($w['school'], $assignment, ['due_on' => '2026-09-20'], $w['actor']);

        $this->assertSame('2026-09-20', $updated->due_on->toDateString());
        $this->assertSame(Assignment::STATUS_PUBLISHED, $updated->status);
    }

    #[Test]
    public function ordinary_fields_remain_editable_once_closed(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_CLOSED]);

        $updated = $this->service()->update($w['school'], $assignment, ['instructions' => 'Corrected instructions'], $w['actor']);

        $this->assertSame('Corrected instructions', $updated->instructions);
        $this->assertSame(Assignment::STATUS_CLOSED, $updated->status);
    }

    #[Test]
    public function an_out_of_range_due_date_update_is_rejected(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);

        $this->expectException(AssignmentDueDateOutsideAcademicYearException::class);

        $this->service()->update($w['school'], $assignment, ['due_on' => '2030-01-01'], $w['actor']);
    }

    #[Test]
    public function an_empty_update_is_a_safe_no_op(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['title' => 'Unchanged']);

        $result = $this->service()->update($w['school'], $assignment, [], $w['actor']);

        $this->assertSame('Unchanged', $result->title);
    }

    // --- lifecycle: legal transitions -------------------------------------

    #[Test]
    public function draft_with_a_due_date_can_be_published(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], [
            'status' => Assignment::STATUS_DRAFT, 'due_on' => '2026-09-15',
        ]);

        $published = $this->service()->publish($w['school'], $assignment, $w['actor']);

        $this->assertSame(Assignment::STATUS_PUBLISHED, $published->status);
    }

    #[Test]
    public function published_can_be_closed(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], [
            'status' => Assignment::STATUS_PUBLISHED, 'due_on' => '2026-09-15',
        ]);

        $closed = $this->service()->close($w['school'], $assignment, $w['actor']);

        $this->assertSame(Assignment::STATUS_CLOSED, $closed->status);
    }

    #[Test]
    public function closed_can_be_republished(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], [
            'status' => Assignment::STATUS_CLOSED, 'due_on' => '2026-09-15',
        ]);

        $republished = $this->service()->publish($w['school'], $assignment, $w['actor']);

        $this->assertSame(Assignment::STATUS_PUBLISHED, $republished->status);
    }

    // --- lifecycle: illegal transitions / due-date gate ---------------------

    #[Test]
    public function publishing_a_draft_with_no_due_date_is_rejected(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT, 'due_on' => null]);

        $this->expectException(AssignmentDueDateRequiredException::class);

        $this->service()->publish($w['school'], $assignment, $w['actor']);
    }

    #[Test]
    public function publishing_already_published_is_rejected(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], [
            'status' => Assignment::STATUS_PUBLISHED, 'due_on' => '2026-09-15',
        ]);

        $this->expectException(AssignmentIllegalTransitionException::class);

        $this->service()->publish($w['school'], $assignment, $w['actor']);
    }

    #[Test]
    public function closing_a_draft_is_rejected(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT]);

        $this->expectException(AssignmentIllegalTransitionException::class);

        $this->service()->close($w['school'], $assignment, $w['actor']);
    }

    #[Test]
    public function closing_an_already_closed_assignment_is_rejected(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_CLOSED, 'due_on' => '2026-09-15']);

        $this->expectException(AssignmentIllegalTransitionException::class);

        $this->service()->close($w['school'], $assignment, $w['actor']);
    }

    // --- audit -----------------------------------------------------------

    #[Test]
    public function creation_publication_and_closure_are_audited_with_bounded_metadata(): void
    {
        $w = $this->assignmentWorld();

        $assignment = $this->service()->create($w['school'], $w['offering']->id, [
            'title' => 'Secret Assignment Title', 'instructions' => 'Secret instructions', 'due_on' => '2026-09-15',
        ], $w['actor']);

        $created = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.created')->firstOrFail());
        $this->assertSame($assignment->id, $created->metadata['assignmentId']);
        $this->assertSame($w['offering']->id, $created->metadata['subjectOfferingId']);
        $this->assertSame('2026-09-15', $created->metadata['dueOn']);
        $this->assertStringNotContainsString('Secret Assignment Title', json_encode($created->metadata));
        $this->assertStringNotContainsString('Secret instructions', json_encode($created->metadata));

        $assignment = $this->service()->publish($w['school'], $assignment, $w['actor']);
        $published = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.published')->firstOrFail());
        $this->assertSame('draft', $published->metadata['previousStatus']);
        $this->assertSame('published', $published->metadata['newStatus']);

        $this->service()->close($w['school'], $assignment, $w['actor']);
        $closed = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.closed')->firstOrFail());
        $this->assertSame('published', $closed->metadata['previousStatus']);
        $this->assertSame('closed', $closed->metadata['newStatus']);
    }

    #[Test]
    public function an_ordinary_update_never_leaks_title_or_instructions_values_into_audit(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);

        $this->service()->update($w['school'], $assignment, [
            'title' => 'A Very Secret Title', 'instructions' => 'A Very Secret Instruction Set',
        ], $w['actor']);

        $updated = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.updated')->firstOrFail());

        $this->assertContains('title', $updated->metadata['changedFields']);
        $this->assertContains('instructions', $updated->metadata['changedFields']);
        $encoded = json_encode($updated->metadata);
        $this->assertStringNotContainsString('A Very Secret Title', $encoded);
        $this->assertStringNotContainsString('A Very Secret Instruction Set', $encoded);
    }
}
