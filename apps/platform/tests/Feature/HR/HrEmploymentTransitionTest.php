<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmploymentAlreadyEndedException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentEffectiveDateException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentStatusTransitionException;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED transition-matrix proof for the tightened
 * App\Domain\HR\Application\EmploymentService::end() (checkpoint brief
 * sections 11/12/21/51): a closed terminal-status set, a
 * concurrency-safe already-ended guard, and an effective-date floor at
 * the EmploymentRecord's own `starts_on`. Exercises `end()` DIRECTLY
 * (not through EmployeeLifecycleService::separate()) to prove the
 * primitive itself is safe for any caller, not only the new lifecycle
 * command layer.
 */
class HrEmploymentTransitionTest extends TestCase
{
    use CreatesTenancyFixtures;

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatuses(): array
    {
        return [
            'separated' => ['separated'],
            'terminated' => ['terminated'],
            'retired' => ['retired'],
            'deceased' => ['deceased'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonTerminalStatuses(): array
    {
        return [
            'active' => ['active'],
            'draft' => ['draft'],
            'pre_joining' => ['pre_joining'],
            'notice_period' => ['notice_period'],
            'unrecognized garbage' => ['not_a_real_status'],
        ];
    }

    #[Test]
    #[DataProvider('terminalStatuses')]
    public function ending_with_any_real_terminal_status_succeeds(string $status): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $ended = app(EmploymentService::class)->end($employment, '2026-03-31', $actor, $status);

        $this->assertSame($status, $ended->status);
        $this->assertSame('2026-03-31', $ended->ends_on->toDateString());
    }

    #[Test]
    #[DataProvider('nonTerminalStatuses')]
    public function ending_with_a_non_terminal_or_unrecognized_status_is_rejected(string $status): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $this->expectException(InvalidEmploymentStatusTransitionException::class);

        app(EmploymentService::class)->end($employment, '2026-03-31', $actor, $status);
    }

    #[Test]
    public function a_rejected_status_leaves_the_employment_completely_unchanged(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        try {
            app(EmploymentService::class)->end($employment, '2026-03-31', $actor, 'active');
        } catch (InvalidEmploymentStatusTransitionException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $fresh = $employment->fresh();
        $this->assertNull($fresh->ends_on);
        $this->assertSame('active', $fresh->status);
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->count());
    }

    #[Test]
    public function an_already_ended_employment_cannot_be_ended_again(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        app(EmploymentService::class)->end($employment, '2025-03-31', $actor, 'separated');

        $this->expectException(EmploymentAlreadyEndedException::class);

        app(EmploymentService::class)->end($employment, '2026-06-30', $actor, 'terminated');
    }

    #[Test]
    public function a_repeated_separation_attempt_does_not_rewrite_the_original_end_date_or_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        app(EmploymentService::class)->end($employment, '2025-03-31', $actor, 'separated');

        try {
            app(EmploymentService::class)->end($employment, '2026-06-30', $actor, 'terminated');
        } catch (EmploymentAlreadyEndedException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $fresh = $employment->fresh();
        $this->assertSame('2025-03-31', $fresh->ends_on->toDateString());
        $this->assertSame('separated', $fresh->status);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->count());
    }

    #[Test]
    public function an_effective_date_before_the_employments_own_start_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-06-01'], $actor);

        $this->expectException(InvalidEmploymentEffectiveDateException::class);

        app(EmploymentService::class)->end($employment, '2022-01-01', $actor, 'separated');
    }

    #[Test]
    public function an_effective_date_equal_to_the_start_date_is_accepted_a_single_day_engagement(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01'], $actor);

        $ended = app(EmploymentService::class)->end($employment, '2026-01-01', $actor, 'separated');

        $this->assertSame('2026-01-01', $ended->ends_on->toDateString());
    }

    #[Test]
    public function ending_still_closes_open_assignments_and_audits_exactly_once_after_the_new_guards(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $actor);

        app(EmploymentService::class)->end($employment, '2026-03-31', $actor, 'separated');

        app(TenantContext::class)->set($school);
        $this->assertSame('2026-03-31', $assignment->fresh()->ends_on->toDateString());
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->count());
    }
}
