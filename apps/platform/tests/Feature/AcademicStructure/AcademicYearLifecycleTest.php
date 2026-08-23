<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearOverlapException;
use App\Domain\AcademicStructure\Application\Exceptions\InvalidAcademicYearTransitionException;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\DomainEventOutbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D sections 14-18, 85: Academic Year date-range/overlap
 * validation and the activate/close lifecycle, including the required
 * transaction -> audit -> outbox proof and its rollback counterpart.
 */
class AcademicYearLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_second_year_overlapping_an_existing_one_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, [
            'code' => 'AY1', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31',
        ]);

        $this->expectException(AcademicYearOverlapException::class);

        app(AcademicYearService::class)->create($school, [
            'name' => 'Overlap', 'code' => 'AY2',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        ]);
    }

    #[Test]
    public function two_years_with_a_shared_boundary_do_not_overlap(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, [
            'code' => 'AY1', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31',
        ]);

        // ends_on of year 1 == starts_on of year 2 is NOT an overlap
        // (half-open range semantics) -- must succeed.
        $year2 = app(AcademicYearService::class)->create($school, [
            'name' => 'Next', 'code' => 'AY2',
            'starts_on' => '2027-05-31', 'ends_on' => '2028-05-30',
        ]);

        $this->assertNotNull($year2->id);
    }

    #[Test]
    public function the_database_check_constraint_rejects_an_inverted_date_range(): void
    {
        $school = $this->createSchool();

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT) so the
        // expected CHECK-constraint failure rolls back cleanly instead
        // of leaving the `pgsql` connection in Postgres's "current
        // transaction is aborted" state for withSchool()'s own
        // RESET-on-exit statement -- see
        // WebhookRlsIsolationTest::raw_insert_for_a_different_school...
        // for the identical pattern.
        app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => AcademicYear::query()->create([
            'school_id' => $school->id,
            'name' => 'Bad', 'code' => 'BAD',
            'starts_on' => '2027-01-01', 'ends_on' => '2026-01-01',
            'status' => 'draft',
        ])));
    }

    #[Test]
    public function activating_a_draft_year_succeeds_and_closes_the_previously_active_one(): void
    {
        $school = $this->createSchool();
        $service = app(AcademicYearService::class);

        $yearA = $this->createAcademicYear($school, ['code' => 'AY-A']);
        $yearB = $this->createAcademicYear($school, [
            'code' => 'AY-B', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31',
        ]);

        $service->activate($yearA);
        $activatedB = $service->activate($yearB);

        $this->assertSame('active', $activatedB->status);
        $refreshedA = app(TenantContext::class)->withSchool($school, fn () => $yearA->refresh());
        $this->assertSame('closed', $refreshedA->status);
    }

    #[Test]
    public function activating_a_non_draft_year_is_rejected(): void
    {
        $school = $this->createSchool();
        $service = app(AcademicYearService::class);
        $year = $this->createAcademicYear($school);

        $service->activate($year);
        $refreshed = app(TenantContext::class)->withSchool($school, fn () => $year->refresh());

        $this->expectException(InvalidAcademicYearTransitionException::class);
        $service->activate($refreshed);
    }

    #[Test]
    public function closing_an_active_year_succeeds(): void
    {
        $school = $this->createSchool();
        $service = app(AcademicYearService::class);
        $year = $this->createAcademicYear($school);

        $service->activate($year);
        $refreshed = app(TenantContext::class)->withSchool($school, fn () => $year->refresh());
        $closed = $service->close($refreshed);

        $this->assertSame('closed', $closed->status);
    }

    #[Test]
    public function closing_a_non_active_year_is_rejected(): void
    {
        $school = $this->createSchool();
        $service = app(AcademicYearService::class);
        $year = $this->createAcademicYear($school);

        $this->expectException(InvalidAcademicYearTransitionException::class);
        $service->close($year);
    }

    #[Test]
    public function activation_is_transactional_and_produces_a_durable_outbox_event(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school);

        app(AcademicYearService::class)->activate($year);

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'academic_year.activated.v1')->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame('pending', $event->status);
        $this->assertSame($year->id, $event->payload['academicYearId']);
        $this->assertNotNull($event->correlation_id);
    }

    #[Test]
    public function a_rolled_back_activation_leaves_neither_the_state_change_nor_the_event(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school);

        try {
            DB::transaction(function () use ($year): void {
                app(AcademicYearService::class)->activate($year);
                throw new RuntimeException('Simulated failure after activation.');
            });
            $this->fail('Expected the RuntimeException to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $refreshed = app(TenantContext::class)->withSchool($school, fn () => $year->refresh());
        $this->assertSame('draft', $refreshed->status);

        $eventCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'academic_year.activated.v1')->count(),
        );
        $this->assertSame(0, $eventCount);
    }
}
