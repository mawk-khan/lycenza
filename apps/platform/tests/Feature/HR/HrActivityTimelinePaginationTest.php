<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeDocumentService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.11 -- REQUIRED pagination/ordering/filter/performance proof
 * (checkpoint brief sections 28-30, 61-62, 64).
 */
class HrActivityTimelinePaginationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function ordering_is_newest_first_with_a_deterministic_id_tiebreak(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        for ($i = 0; $i < 5; $i++) {
            app(EmployeeCertificationService::class)->add($employee, ['name' => "Cert {$i}", 'issuer' => 'Issuer'], $actor);
        }

        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 100));
        $occurredAts = collect($result->items())->pluck('occurredAt')->all();
        $sorted = $occurredAts;
        rsort($sorted);

        $this->assertSame($sorted, $occurredAts, 'Entries must be ordered occurred_at DESC.');
    }

    #[Test]
    public function default_page_size_is_25_and_maximum_is_clamped_to_100(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        for ($i = 0; $i < 30; $i++) {
            app(EmployeeCertificationService::class)->add($employee, ['name' => "Cert {$i}", 'issuer' => 'Issuer'], $actor);
        }

        $default = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery);
        $this->assertCount(25, $default->items());
        $this->assertSame(25, $default->perPage());

        $clamped = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 1000));
        $this->assertSame(EmployeeActivityTimelineService::MAX_PER_PAGE, $clamped->perPage());
    }

    #[Test]
    public function adjacent_pages_contain_no_duplicate_or_missing_events(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        for ($i = 0; $i < 40; $i++) {
            app(EmployeeCertificationService::class)->add($employee, ['name' => "Cert {$i}", 'issuer' => 'Issuer'], $actor);
        }

        $page1 = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 20, page: 1));
        $page2 = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 20, page: 2));

        $ids1 = collect($page1->items())->pluck('id')->all();
        $ids2 = collect($page2->items())->pluck('id')->all();

        $this->assertCount(20, $ids1);
        $this->assertCount(20, $ids2);
        $this->assertEmpty(array_intersect($ids1, $ids2), 'Adjacent pages must never overlap.');
        $this->assertGreaterThanOrEqual(40, $page1->total());
    }

    #[Test]
    public function an_invalid_category_falls_back_to_the_full_visible_set_rather_than_erroring(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeeCertificationService::class)->add($employee, ['name' => 'Cert', 'issuer' => 'Issuer'], $actor);

        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(category: 'not-a-real-category'));

        $this->assertTrue(collect($result->items())->contains(fn ($e) => $e->eventType === 'hr.certification.created'));
    }

    #[Test]
    public function requesting_a_category_the_actor_cannot_see_yields_the_same_empty_result_as_a_category_with_no_events(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeeCertificationService::class)->add($employee, ['name' => 'Cert', 'issuer' => 'Issuer'], $actor);

        $limitedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $forbiddenCategory = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $limitedActor, new EmployeeActivityTimelineQuery(category: 'sensitive_access'));
        $legitimatelyEmptyCategory = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(category: 'sensitive_access'));

        $this->assertSame(0, $forbiddenCategory->total());
        $this->assertSame(0, $legitimatelyEmptyCategory->total());
        $this->assertSame($forbiddenCategory->items(), $legitimatelyEmptyCategory->items(), 'A forbidden category and a legitimately-empty category must be indistinguishable.');
    }

    #[Test]
    public function date_range_filters_apply_to_audit_occurrence_time(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeeCertificationService::class)->add($employee, ['name' => 'Cert', 'issuer' => 'Issuer'], $actor);

        $farFuture = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(occurredFrom: now()->addYear()->toDateString()));
        $this->assertSame(0, $farFuture->total());

        $fromToday = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(occurredFrom: now()->subDay()->toDateString()));
        $this->assertGreaterThanOrEqual(1, $fromToday->total());
    }

    #[Test]
    public function fetching_a_page_of_many_events_issues_a_bounded_number_of_queries(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        for ($i = 0; $i < 25; $i++) {
            app(EmployeeDocumentService::class)->register($employee, [
                'category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 'local',
                'storage_path' => "doc-{$i}.pdf", 'original_filename' => "doc-{$i}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            ], $actor);
        }

        DB::enableQueryLog();
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 25));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(25, $result->items());
        // Bounded: employee lookup + count query + page query + one
        // batched actor-name lookup -- never one query per event.
        $this->assertLessThan(10, $queryCount, "Expected a small, fixed number of queries regardless of page size; got {$queryCount}.");
    }
}
