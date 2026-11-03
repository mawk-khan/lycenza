<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Application\CurrentEmployeeAssignments;
use App\Domain\HR\Application\Exceptions\HrAssignmentUnavailableException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\Exceptions\InvalidPlacementChangeException;
use App\Domain\HR\Application\Exceptions\PlacementChangeConflictException;
use App\Domain\HR\Application\ReportingLine;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.1A: the named, dated, reasoned placement change. The source primary
 * assignment closes the day before; a new primary opens on the effective
 * date; history answers both dates for ever.
 */
class PlacementChangeServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Position $teacher;

    private Position $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'Campus A']);
        $this->b = $this->createCampus($this->school, ['name' => 'Campus B']);
        $this->teacher = $this->createPosition($this->school, ['name' => 'Primary Teacher', 'status' => 'active']);
        $this->coordinator = $this->createPosition($this->school, ['name' => 'Academic Coordinator', 'status' => 'active']);
    }

    /** @return array{0: EmploymentRecord, 1: EmployeeAssignment} */
    private function placed(?Campus $campus = null, string $starts = '2026-04-01'): array
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => $starts, 'ends_on' => null]);
        $assignment = $this->createEmployeeAssignment($employment, $this->teacher, ['campus_id' => ($campus ?? $this->a)->id, 'is_primary' => true, 'starts_on' => $starts, 'ends_on' => null]);

        return [$employment, $assignment];
    }

    private function change(EmployeeAssignment $source, PlacementChangeKind $kind, string $on, Position $position, ?Campus $campus, ?User $actor = null, PlacementChangeReason $reason = PlacementChangeReason::CAREER_PROGRESSION): EmployeeAssignment
    {
        return app(PlacementChangeService::class)->change($source, $kind, $on, $reason, $position, $actor ?? $this->fullHrActor($this->school), campus: $campus);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    #[Test]
    public function a_promotion_with_transfer_preserves_the_old_placement_and_history_answers_both_dates(): void
    {
        [$employment, $source] = $this->placed();
        $actor = $this->fullHrActor($this->school);

        $new = $this->change($source, PlacementChangeKind::PROMOTION, '2026-11-01', $this->coordinator, $this->b, $actor);

        $old = $this->inSchool(fn () => EmployeeAssignment::query()->findOrFail($source->id));
        $this->assertSame('2026-10-31', $old->ends_on?->toDateString());
        $this->assertTrue($old->is_primary, 'the old placement stays primary for the period it covered');
        $this->assertSame(['2026-11-01', null, true, $this->b->id, $this->coordinator->id, $employment->id], [
            $new->starts_on->toDateString(), $new->ends_on, $new->is_primary, $new->campus_id, $new->position_id, $new->employment_record_id,
        ]);

        $reader = app(WorkforceAsOfReader::class);
        $before = $reader->placementOn($this->school, $employment->employee_id, '2026-10-15');
        $after = $reader->placementOn($this->school, $employment->employee_id, '2026-11-15');
        $this->assertSame([$this->a->id, $this->teacher->id, $source->id], [$before?->campusId, $before?->positionId, $before?->primaryAssignmentId]);
        $this->assertSame([$this->b->id, $this->coordinator->id, $new->id], [$after?->campusId, $after?->positionId, $after?->primaryAssignmentId]);

        $record = $this->inSchool(fn () => EmployeePlacementChange::query()->where('to_assignment_id', $new->id)->firstOrFail());
        $this->assertSame(['promotion', $source->id, '2026-11-01', 'career_progression', $actor->id], [
            $record->change_kind, $record->from_assignment_id, $record->effective_on->toDateString(), $record->reason_code, $record->recorded_by_user_id,
        ]);

        $audit = $this->inSchool(fn () => DB::table('school_audit_events')->where('school_id', $this->school->id)->where('event_type', 'hr.placement.changed')->first());
        $this->assertNotNull($audit);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame(['promotion', 'career_progression', '2026-11-01', $source->id, $new->id, ['campus', 'position']], [
            $metadata['kind'], $metadata['reason'], $metadata['effectiveOn'], $metadata['fromAssignmentId'], $metadata['toAssignmentId'], $metadata['fields'],
        ]);
        // The employment is the same row: Payroll, Leave and attendance keep resolving it.
        $this->assertSame($employment->id, $this->inSchool(fn () => app(CurrentEmployeeAssignments::class)->currentEmployment($this->school, $employment->employee_id)?->id));
    }

    #[Test]
    public function each_kind_must_match_what_changes_and_something_must_change(): void
    {
        [, $source] = $this->placed();

        foreach ([
            [PlacementChangeKind::TRANSFER, $this->coordinator, $this->a, 'kind_mismatch'],
            [PlacementChangeKind::PROMOTION, $this->teacher, $this->b, 'kind_mismatch'],
            [PlacementChangeKind::DEPARTMENT_CHANGE, $this->teacher, $this->b, 'kind_mismatch'],
            [PlacementChangeKind::TRANSFER, $this->teacher, $this->a, 'no_change'],
        ] as [$kind, $position, $campus, $reason]) {
            try {
                $this->change($source, $kind, '2026-11-01', $position, $campus);
                $this->fail("{$kind->value} must be refused ({$reason})");
            } catch (InvalidPlacementChangeException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        $new = $this->change($source, PlacementChangeKind::TRANSFER, '2026-11-01', $this->teacher, $this->b);
        $this->assertSame($this->b->id, $new->campus_id);
    }

    #[Test]
    public function a_department_change_and_a_designation_change_are_named(): void
    {
        [$employment, $source] = $this->placed();
        $department = $this->createDepartment($this->school, ['status' => 'active']);

        $moved = app(PlacementChangeService::class)->change($source, PlacementChangeKind::DEPARTMENT_CHANGE, '2026-09-01', PlacementChangeReason::RESTRUCTURING, $this->teacher, $this->fullHrActor($this->school), campus: $this->a, department: $department);
        $renamed = $this->change($moved, PlacementChangeKind::DESIGNATION_CHANGE, '2026-10-01', $this->coordinator, $this->a);

        $kinds = $this->inSchool(fn () => EmployeePlacementChange::query()->where('employment_record_id', $employment->id)->orderBy('effective_on')->pluck('change_kind')->all());
        $this->assertSame(['department_change', 'designation_change'], $kinds);
        $this->assertNull($renamed->department_id, 'the target placement is stated in full (no Department given = none)');
    }

    #[Test]
    public function a_retroactive_transfer_is_recorded_by_whole_school_hr(): void
    {
        [$employment, $source] = $this->placed();

        $this->change($source, PlacementChangeKind::TRANSFER, '2026-06-01', $this->teacher, $this->b);

        $reader = app(WorkforceAsOfReader::class);
        $this->assertSame($this->a->id, $reader->placementOn($this->school, $employment->employee_id, '2026-05-31')?->campusId);
        $this->assertSame($this->b->id, $reader->placementOn($this->school, $employment->employee_id, '2026-06-01')?->campusId);
    }

    #[Test]
    public function the_source_must_be_the_primary_covering_the_date_and_start_before_it(): void
    {
        [, $source] = $this->placed();
        [, $secondaryOf] = $this->placed();
        $secondary = $this->createEmployeeAssignment($this->inSchool(fn () => EmploymentRecord::query()->findOrFail($secondaryOf->employment_record_id)), $this->teacher, ['campus_id' => $this->b->id, 'is_primary' => false, 'starts_on' => '2026-04-01']);

        foreach ([[$source, '2026-04-01'], [$source, '2026-03-01'], [$secondary, '2026-11-01']] as [$assignment, $on]) {
            try {
                $this->change($assignment, PlacementChangeKind::TRANSFER, $on, $this->teacher, $assignment->campus_id === $this->a->id ? $this->b : $this->a);
                $this->fail('refused');
            } catch (PlacementChangeConflictException $e) {
                $this->assertSame('source_not_primary_on_date', $e->reason);
            }
        }
    }

    #[Test]
    public function the_manager_is_kept_and_direct_reports_follow_the_new_placement(): void
    {
        [, $manager] = $this->placed();
        [$employment, $source] = $this->placed();
        [$reportEmployment, $report] = $this->placed();
        $this->inSchool(function () use ($source, $manager, $report): void {
            EmployeeAssignment::query()->whereKey($source->id)->update(['manager_assignment_id' => $manager->id]);
            EmployeeAssignment::query()->whereKey($report->id)->update(['manager_assignment_id' => $source->id]);
        });

        $new = $this->change($source, PlacementChangeKind::TRANSFER, '2026-11-01', $this->teacher, $this->b);

        $this->assertSame($manager->id, $new->manager_assignment_id);
        $this->assertSame($new->id, $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($report->id)->value('manager_assignment_id')));
        $this->assertSame($employment->employee_id, $this->inSchool(fn () => app(ReportingLine::class)->managerOf($this->school, $reportEmployment->id, '2026-11-15')));
    }

    #[Test]
    public function a_future_change_of_someone_with_reports_waits_and_one_without_is_recorded(): void
    {
        [, $source] = $this->placed();
        [, $report] = $this->placed();
        $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($report->id)->update(['manager_assignment_id' => $source->id]));

        try {
            $this->change($source, PlacementChangeKind::TRANSFER, '2026-12-01', $this->teacher, $this->b);
            $this->fail('refused');
        } catch (PlacementChangeConflictException $e) {
            $this->assertSame('future_change_with_reports', $e->reason);
        }

        [$employment, $alone] = $this->placed();
        $new = $this->change($alone, PlacementChangeKind::TRANSFER, '2026-12-01', $this->teacher, $this->b);
        $this->assertSame('2026-12-01', $new->starts_on->toDateString());
        $this->assertSame($this->a->id, app(WorkforceAsOfReader::class)->placementOn($this->school, $employment->employee_id, '2026-11-15')?->campusId, 'still Campus A today');
    }

    #[Test]
    public function an_ended_employment_keeps_its_placement_history(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01', 'ends_on' => '2026-08-31', 'status' => 'separated']);
        $source = $this->createEmployeeAssignment($employment, $this->teacher, ['campus_id' => $this->a->id, 'is_primary' => true, 'starts_on' => '2026-01-01', 'ends_on' => '2026-08-31']);

        $this->expectException(InvalidPlacementChangeException::class);
        $this->change($source, PlacementChangeKind::TRANSFER, '2026-06-01', $this->teacher, $this->b);
    }

    #[Test]
    public function nobody_changes_their_own_placement(): void
    {
        [$employment, $source] = $this->placed();
        $actor = $this->fullHrActor($this->school);
        $this->inSchool(fn () => Employee::query()->whereKey($employment->employee_id)->update(['user_id' => $actor->id]));

        $this->expectException(HrSelfAdministrationException::class);
        $this->change($source, PlacementChangeKind::TRANSFER, '2026-11-01', $this->teacher, $this->b, $actor);
    }

    #[Test]
    public function an_actor_without_assignment_management_is_refused(): void
    {
        [, $source] = $this->placed();

        foreach ([[], ['hr.employees.view', 'hr.employees.assignments.view']] as $capabilities) {
            try {
                $this->change($source, PlacementChangeKind::TRANSFER, '2026-11-01', $this->teacher, $this->b, $this->createUserWithCapabilities($this->school, $capabilities));
                $this->fail('refused');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($this->inSchool(fn () => EmployeeAssignment::query()->whereKey($source->id)->value('ends_on')), 'nothing written');
    }

    #[Test]
    public function a_campus_scoped_hr_officer_transfers_only_inside_scope_and_from_today(): void
    {
        $c = $this->createCampus($this->school, ['name' => 'Campus C']);
        $hr = $this->createUser();
        $membership = $this->createMembership($hr, $this->school);
        $this->assignSchoolRole($membership, 'hr_officer', $this->a->id);
        $this->assignSchoolRole($membership, 'hr_officer', $this->b->id);

        [, $backdated] = $this->placed();
        try {
            $this->change($backdated, PlacementChangeKind::TRANSFER, '2026-11-01', $this->teacher, $this->b, $hr);
            $this->fail('backdated is whole-School history');
        } catch (HrAssignmentUnavailableException) {
            $this->addToAssertionCount(1);
        }

        [, $outside] = $this->placed();
        try {
            $this->change($outside, PlacementChangeKind::TRANSFER, '2026-11-15', $this->teacher, $c, $hr);
            $this->fail('destination out of scope');
        } catch (HrAssignmentUnavailableException) {
            $this->addToAssertionCount(1);
        }

        [, $ok] = $this->placed();
        $new = $this->change($ok, PlacementChangeKind::TRANSFER, '2026-11-15', $this->teacher, $this->b, $hr);
        $this->assertSame($this->b->id, $new->campus_id);
    }
}
