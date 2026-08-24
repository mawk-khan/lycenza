<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeeSensitiveDocumentReadService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED HTTP contract proof for the Activity Timeline
 * API (`GET /api/v1/schools/{school}/employees/{employee}/activity`),
 * checkpoint brief section 75. A thin adapter over the already-proven
 * `EmployeeActivityTimelineService` (8A.11) -- these tests exercise the
 * real HTTP path, not the underlying category-filtering/sensitivity
 * logic itself (already exhaustively proven in 8A.11's own tests).
 */
class HrEmployeeActivityApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")->assertUnauthorized();
    }

    #[Test]
    public function directory_capability_alone_does_not_grant_timeline_access(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertForbidden();
    }

    #[Test]
    public function a_cross_school_employee_is_a_safe_not_found(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $employeeB = $this->createEmployee($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeB->id}/activity")
            ->assertNotFound();
    }

    #[Test]
    public function employment_events_require_the_assignments_view_category_capability(): void
    {
        $school = $this->createSchool();
        $setupActor = $this->fullHrActor($school);
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Timeline Category Test'], $setupActor);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $setupActor);

        $limitedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($limitedActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertOk();

        $eventTypes = collect($response->json('data'))->pluck('event_type')->all();
        $this->assertNotContains('hr.employment.created', $eventTypes, 'Employment events require hr.employees.assignments.view, not granted here.');
        $this->assertContains('employee.created', $eventTypes);
    }

    #[Test]
    public function raw_audit_metadata_never_appears_in_the_response(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $this->createEmployeePersonalDetail($employee, ['personal_email' => 'timeline-sentinel@example.com']);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('timeline-sentinel@example.com', $raw);
        $this->assertStringNotContainsString('metadata', $raw);
    }

    #[Test]
    public function documents_view_only_actor_never_sees_highly_sensitive_history(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'contract', 'classification_tier' => 'restricted', 'storage_path' => 'docs/contract.pdf', 'storage_disk' => 'local', 'original_filename' => 'contract.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1000,
        ], $actor);
        app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'highly_sensitive'], $actor);
        app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);
        app(TenantContext::class)->set($school);
        $document = $document->fresh();
        app(EmployeeDocumentService::class)->update($employee, $document, ['category' => 'updated-while-sensitive'], $actor);
        app(TenantContext::class)->set($school);
        $document = $document->fresh();
        app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'restricted'], $actor);

        $documentsOnlyActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($documentsOnlyActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?per_page=100")
            ->assertOk();

        $eventTypes = collect($response->json('data'))->pluck('event_type')->all();
        $this->assertSame(1, collect($eventTypes)->filter(fn ($t) => $t === 'hr.employee_document.created')->count(), 'Only the original restricted creation is visible.');
        $this->assertNotContains('hr.employee_document.sensitive_viewed', $eventTypes);
    }

    #[Test]
    public function sensitive_view_actor_sees_the_full_document_history(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'contract', 'classification_tier' => 'restricted', 'storage_path' => 'docs/contract.pdf', 'storage_disk' => 'local', 'original_filename' => 'contract.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1000,
        ], $actor);
        app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'highly_sensitive'], $actor);
        app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee->id, $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?per_page=100")
            ->assertOk();

        $eventTypes = collect($response->json('data'))->pluck('event_type')->all();
        $this->assertContains('hr.employee_document.sensitive_viewed', $eventTypes);
        $this->assertContains('hr.employee_document.updated', $eventTypes);
    }

    #[Test]
    public function the_pagination_total_reflects_visible_events_only(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'contract', 'classification_tier' => 'highly_sensitive', 'storage_path' => 'docs/hs.pdf', 'storage_disk' => 'local', 'original_filename' => 'hs.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1000,
        ], $actor);

        $documentsOnlyActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($documentsOnlyActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?category=document")
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'), 'total() must reflect only visible events -- the hidden highly_sensitive creation must not be counted.');
    }

    #[Test]
    public function ordering_is_stable_newest_first(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertOk();

        $occurredAts = collect($response->json('data'))->pluck('occurred_at')->all();
        $sorted = $occurredAts;
        rsort($sorted);
        $this->assertSame($sorted, $occurredAts);
    }

    #[Test]
    public function default_and_max_page_size_are_respected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $default = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertOk();
        $this->assertSame(25, $default->json('meta.perPage'));

        $max = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?per_page=10000")
            ->assertOk();
        $this->assertSame(100, $max->json('meta.perPage'));
    }

    #[Test]
    public function an_unrecognized_category_is_treated_as_no_filter_never_an_error(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?category=not-a-real-category")
            ->assertOk();
    }

    #[Test]
    public function date_range_filters_are_applied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?occurred_from=2099-01-01")
            ->assertOk();

        $this->assertSame([], $response->json('data'), 'A future occurred_from must exclude every already-recorded event.');
    }

    #[Test]
    public function there_is_no_free_text_search_parameter(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        // A `search`/`q` query parameter is silently ignored -- it is
        // not part of EmployeeActivityTimelineQuery's contract and this
        // controller never reads it.
        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity?search=anything")
            ->assertOk();
    }
}
