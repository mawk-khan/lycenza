<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeeSensitiveDocumentReadService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.11 -- REQUIRED sensitive-history proof (checkpoint brief
 * sections 19/20/60): a full EmployeeDocument classification history --
 * restricted created, restricted -> highly_sensitive, sensitive viewed,
 * a further highly_sensitive update, highly_sensitive -> restricted --
 * exercised end to end. An actor with `documents.view` only must see
 * NONE of the classification-sensitive events (not even a count hint);
 * an actor who additionally holds `sensitive.view` sees all of them.
 *
 * This also proves the FAIL-CLOSED rule directly: `hr.employee_document.updated`
 * carries no before/after tier in its OWN right (only `classificationChanged`
 * and the 8A.11-added resulting `classificationTier`) -- ANY
 * `classificationChanged = true` event is treated as sensitive
 * regardless of its resulting tier, because the transition itself
 * (in either direction) is Highly Sensitive information, not just the
 * resulting state.
 */
class HrActivityTimelineSensitiveHistoryTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_full_classification_history_is_fully_hidden_from_an_ordinary_documents_viewer_and_fully_visible_with_sensitive_view(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        // 1. restricted created
        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 'local',
            'storage_path' => 'a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ], $actor);

        // 2. restricted -> highly_sensitive
        $document = app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'highly_sensitive'], $actor);

        // 3. sensitive viewed
        app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);

        // 4. a further highly_sensitive update (non-classification field)
        $document = app(EmployeeDocumentService::class)->update($employee, $document, ['category' => 'other'], $actor);

        // 5. highly_sensitive -> restricted
        app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'restricted'], $actor);

        // Actor A: documents.view only.
        $actorA = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);
        $resultA = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actorA, new EmployeeActivityTimelineQuery(perPage: 100));

        $documentEventsForA = collect($resultA->items())->filter(fn ($e) => in_array($e->category, ['document', 'sensitive_access'], true));
        $this->assertCount(1, $documentEventsForA, 'Actor A must see exactly the one non-sensitive event (the original restricted creation) and nothing else from this history.');
        $this->assertSame('hr.employee_document.created', $documentEventsForA->first()->eventType);
        $this->assertSame(1, $resultA->total(), 'The paginator total must reflect VISIBLE events only -- never a count that includes the 4 hidden sensitive events.');

        // Actor B: documents.view + sensitive.view.
        $actorB = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view', 'hr.employees.sensitive.view']);
        $resultB = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actorB, new EmployeeActivityTimelineQuery(perPage: 100));

        $documentEventsForB = collect($resultB->items())->filter(fn ($e) => in_array($e->category, ['document', 'sensitive_access'], true));
        $this->assertCount(5, $documentEventsForB, 'Actor B must see the full 5-event history.');
        $this->assertSame(5, $resultB->total());
    }

    #[Test]
    public function a_classificationchanged_true_event_is_sensitive_even_when_the_resulting_tier_is_restricted(): void
    {
        // Isolates the specific fail-closed rule: the TRANSITION itself
        // (highly_sensitive -> restricted) is sensitive, even though the
        // event's resulting/current tier is the non-sensitive one.
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof', 'classification_tier' => 'highly_sensitive', 'storage_disk' => 'local',
            'storage_path' => 'a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ], $actor);

        app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'restricted'], $actor);

        $actorA = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actorA, new EmployeeActivityTimelineQuery(perPage: 100));

        $this->assertCount(0, collect($result->items())->filter(fn ($e) => in_array($e->category, ['document', 'sensitive_access'], true)), 'Neither the highly_sensitive creation nor the downgrade-to-restricted update may be visible.');
    }

    #[Test]
    public function an_ordinary_restricted_update_with_no_classification_change_remains_visible_to_documents_view(): void
    {
        // The negative control: a mundane restricted-document edit
        // (category change, no classification touch at all) must NOT
        // be swept up by the fail-closed rule -- only genuine
        // classification transitions/highly_sensitive content are.
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 'local',
            'storage_path' => 'a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ], $actor);

        app(EmployeeDocumentService::class)->update($employee, $document, ['category' => 'other'], $actor);

        $actorA = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actorA, new EmployeeActivityTimelineQuery(perPage: 100));

        $this->assertTrue(collect($result->items())->contains(fn ($e) => $e->eventType === 'hr.employee_document.updated'));
    }
}
