<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Application\CurrentEmployeeAssignments;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\AssignmentOutsideEmploymentRangeException;
use App\Domain\HR\Application\Exceptions\EmploymentAlreadyEndedException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentTermsException;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C2 -- HRC-1 (an employment created with a planned `ends_on` was a
 * dead end), HRC-2 (ending an employment with a future-dated placement failed
 * on `employee_assignments_date_range_check`) and HRC-12 (a new employment for an
 * Employee recorded deceased; the archived case stays an owner decision). Today is 2026-11-15 (Asia/Kolkata School).
 */
class WorkforceLifecycleIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Position $teacher;

    private Position $coordinator;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'Campus A']);
        $this->b = $this->createCampus($this->school, ['name' => 'Campus B']);
        $this->teacher = $this->createPosition($this->school, ['name' => 'Primary Teacher', 'status' => 'active']);
        $this->coordinator = $this->createPosition($this->school, ['name' => 'Academic Coordinator', 'status' => 'active']);
        $this->hr = $this->fullHrActor($this->school);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @return array{0: EmploymentRecord, 1: EmployeeAssignment} */
    private function placed(string $starts = '2026-04-01'): array
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => $starts, 'ends_on' => null, 'status' => 'active']);
        $assignment = $this->createEmployeeAssignment($employment, $this->teacher, ['campus_id' => $this->a->id, 'is_primary' => true, 'starts_on' => $starts, 'ends_on' => null]);

        return [$employment, $assignment];
    }

    private function transfer(EmployeeAssignment $source, string $on, ?Campus $campus = null, ?Position $position = null): EmployeeAssignment
    {
        return app(PlacementChangeService::class)->change($source, PlacementChangeKind::TRANSFER, $on, PlacementChangeReason::CAREER_PROGRESSION,
            $position ?? $this->teacher, $this->hr, campus: $campus ?? $this->b);
    }

    private function end(EmploymentRecord $employment, string $on): EmploymentRecord
    {
        return app(EmploymentService::class)->end($this->inSchool(fn () => $employment->fresh()), $on, $this->hr);
    }

    /** @return array<string, array{0: string, 1: string|null}> assignment id => [starts_on, ends_on] */
    private function ranges(EmploymentRecord $employment): array
    {
        return $this->inSchool(fn () => DB::table('employee_assignments')->where('employment_record_id', $employment->id)->orderBy('starts_on')->get()
            ->mapWithKeys(fn ($a) => [(string) $a->id => [(string) $a->starts_on, $a->ends_on === null ? null : (string) $a->ends_on]])->all());
    }

    private function api(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->hr->createToken('t')->plainTextToken)->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    // --- HRC-1 -----------------------------------------------------------------

    #[Test]
    public function hrc_1_the_api_refuses_an_employment_that_begins_with_a_planned_end(): void
    {
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->api()->postJson("/api/v1/schools/{$this->school->id}/employees/{$employee->id}/employment-records", [
            'employment_type' => 'permanent', 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31', 'status' => 'active',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['ends_on']]]);
        $this->assertSame(0, $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->count()), 'nothing recorded');

        // The valid creation still works, and that employment ends normally (no dead end).
        $this->flushHeaders();
        $id = $this->api()->postJson("/api/v1/schools/{$this->school->id}/employees/{$employee->id}/employment-records", [
            'employment_type' => 'permanent', 'starts_on' => '2026-11-01', 'status' => 'active',
        ])->assertCreated()->json('data.id');
        $ended = $this->end($this->inSchool(fn () => EmploymentRecord::query()->findOrFail($id)), '2026-11-14');
        $this->assertSame(['2026-11-14', 'separated'], [$ended->ends_on->toDateString(), $ended->status]);
    }

    #[Test]
    public function hrc_1_the_service_refuses_it_for_every_caller_and_rehire_does_too(): void
    {
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        try {
            app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31'], $this->hr);
            $this->fail('a planned end was recorded at creation');
        } catch (InvalidEmploymentTermsException $e) {
            $this->assertSame('ends_on_on_create', $e->reason);
        }
        // Future onboarding (pre-joining) still works.
        $pre = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-12-01', 'status' => 'pre_joining'], $this->hr);
        $this->assertSame('pre_joining', $pre->status);

        // Rehire through the API refuses it the same way.
        $former = $this->createEmployee($this->school, ['user_id' => null]);
        $this->createEmploymentRecord($former, ['starts_on' => '2024-01-01', 'ends_on' => '2025-12-31', 'status' => 'separated']);
        $this->flushHeaders();
        $this->api()->postJson("/api/v1/schools/{$this->school->id}/employees/{$former->id}/rehire", [
            'employment_type' => 'permanent', 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['ends_on']]]);
    }

    #[Test]
    public function hrc_1_the_runtime_role_cannot_insert_an_open_employment_with_an_end(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        $row = ['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employee->id, 'employment_type' => 'permanent',
            'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()];
        try {
            DB::transaction(fn () => $this->inSchool(fn () => DB::table('employment_records')->insert($row)));
            $this->fail('the runtime role recorded an open employment with an end');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never begins with a planned end', $e->getMessage());
        }
        // An already-ended employment (terminal status) is still a coherent record.
        $this->inSchool(fn () => DB::table('employment_records')->insert(['id' => (string) Str::uuid7(), 'status' => 'separated', 'starts_on' => '2024-01-01', 'ends_on' => '2024-12-31'] + $row));
        $this->assertSame(1, $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->count()));
    }

    // --- HRC-2 -----------------------------------------------------------------

    #[Test]
    public function hrc_2_ending_before_a_future_transfer_keeps_the_plan_as_history_and_never_in_effect(): void
    {
        [$employment, $source] = $this->placed();
        $future = $this->transfer($source, '2026-12-01');
        $change = $this->inSchool(fn () => EmployeePlacementChange::query()->where('to_assignment_id', $future->id)->firstOrFail());

        // Before HR.R1-C2 this failed: employee_assignments_date_range_check (2026-12-01 .. 2026-11-20).
        $ended = $this->end($employment, '2026-11-20');

        $this->assertSame(['2026-11-20', 'separated'], [$ended->ends_on->toDateString(), $ended->status]);
        $this->assertSame([
            $source->id => ['2026-04-01', '2026-11-20'], // clipped to the last day (its planned end was 2026-11-30)
            $future->id => ['2026-12-01', '2026-11-30'], // never took effect: the empty interval
        ], $this->ranges($employment));
        $this->assertTrue($this->inSchool(fn () => EmployeePlacementChange::query()->whereKey($change->id)->exists()), 'the planned transfer stays as history');

        $reader = app(WorkforceAsOfReader::class);
        $this->assertSame($source->id, $this->inSchool(fn () => $reader->placementOn($this->school, $employment->employee_id, '2026-11-18')?->primaryAssignmentId), 'before the end: the earlier placement');
        $this->assertNull($this->inSchool(fn () => $reader->placementOn($this->school, $employment->employee_id, '2026-12-05')), 'after the end: nothing, not the cancelled transfer');
        $this->assertSame(0, $this->inSchool(fn () => app(CurrentEmployeeAssignments::class)->currentAssignmentsOf($this->school, [$employment->employee_id], null, '2026-12-05')->count()));

        // Ending again is the existing controlled refusal, never a second disposition.
        $this->expectException(EmploymentAlreadyEndedException::class);
        $this->end($employment, '2026-11-20');
    }

    #[Test]
    public function hrc_2_a_placement_starting_on_the_last_day_is_a_one_day_placement(): void
    {
        [$employment, $source] = $this->placed();
        $future = $this->transfer($source, '2026-11-20');
        $this->end($employment, '2026-11-20');
        $this->assertSame([$source->id => ['2026-04-01', '2026-11-19'], $future->id => ['2026-11-20', '2026-11-20']], $this->ranges($employment));
    }

    #[Test]
    public function hrc_2_several_future_changes_all_become_never_effective_and_the_movement_report_omits_them(): void
    {
        [$employment, $source] = $this->placed();
        $first = $this->transfer($source, '2026-12-01');
        $second = app(PlacementChangeService::class)->change($first, PlacementChangeKind::PROMOTION, '2027-01-01', PlacementChangeReason::CAREER_PROGRESSION,
            $this->coordinator, $this->hr, campus: $this->b);
        $this->end($employment, '2026-11-20');

        $this->assertSame([
            $source->id => ['2026-04-01', '2026-11-20'],
            $first->id => ['2026-12-01', '2026-11-30'],
            $second->id => ['2027-01-01', '2026-12-31'],
        ], $this->ranges($employment));

        // The movement report (HR.14) lists the end, never the cancelled transfer or promotion (read once the
        // whole range is in the past).
        $this->travelTo(CarbonImmutable::parse('2027-03-01T06:00:00Z'));
        $this->actingAs($this->hr)->post("/app/schools/{$this->school->id}/activate");
        $rows = fn (string $movement) => $this->get(route('app.hr.reports.show', ['report' => 'hr.staff_movements', 'from' => '2026-11-01', 'to' => '2027-02-28', 'movement' => $movement], false))
            ->assertOk()->viewData('page')['props']['rows']['data'];
        $this->assertCount(1, $rows('ended'));
        $this->assertCount(0, $rows('transfer'), 'a cancelled plan is not a movement');
        $this->assertCount(0, $rows('promotion'));
    }

    #[Test]
    public function hrc_2_an_assignment_already_ended_stays_untouched(): void
    {
        [$employment, $source] = $this->placed();
        $this->inSchool(fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['ends_on' => '2026-06-30']));
        $second = $this->createEmployeeAssignment($employment, $this->teacher, ['campus_id' => $this->a->id, 'is_primary' => true, 'starts_on' => '2026-07-01', 'ends_on' => null]);
        $this->end($employment, '2026-11-20');
        $this->assertSame([$source->id => ['2026-04-01', '2026-06-30'], $second->id => ['2026-07-01', '2026-11-20']], $this->ranges($employment));
    }

    #[Test]
    public function hrc_2_rehire_never_revives_the_old_or_cancelled_placements(): void
    {
        [$employment, $source] = $this->placed();
        $future = $this->transfer($source, '2026-12-01');
        $this->end($employment, '2026-11-20');

        $rehired = app(EmployeeLifecycleService::class)->rehire($this->inSchool(fn () => $employment->employee()->first()), ['employment_type' => 'permanent', 'starts_on' => '2027-01-04'], $this->hr);
        $this->assertNotSame($employment->id, $rehired->id);
        $this->assertSame(0, $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $rehired->id)->count()), 'no assignment carried over');
        $this->assertSame(['2026-12-01', '2026-11-30'], $this->ranges($employment)[$future->id], 'the cancelled plan stays cancelled');
    }

    #[Test]
    public function hrc_2_the_empty_interval_is_the_only_new_shape_the_database_admits(): void
    {
        [$employment, $source] = $this->placed();
        try {
            DB::transaction(fn () => $this->inSchool(fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['ends_on' => '2026-03-15'])));
            $this->fail('an impossible range was stored');
        } catch (QueryException $e) {
            $this->assertStringContainsString('employee_assignments_date_range_check', $e->getMessage());
        }
    }

    // --- HRC-12 ----------------------------------------------------------------

    #[Test]
    public function hrc_12_an_employee_recorded_deceased_never_receives_another_employment(): void
    {
        $deceased = $this->createEmployee($this->school, ['user_id' => null]);
        $this->createEmploymentRecord($deceased, ['starts_on' => '2024-01-01', 'ends_on' => '2025-12-31', 'status' => 'deceased']);
        $this->assertRefused(fn () => app(EmployeeLifecycleService::class)->rehire($this->inSchool(fn () => $deceased->fresh()), ['employment_type' => 'permanent', 'starts_on' => '2027-01-04'], $this->hr));
        $this->assertRefused(fn () => app(EmploymentService::class)->create($this->inSchool(fn () => $deceased->fresh()), ['employment_type' => 'permanent', 'starts_on' => '2027-01-04'], $this->hr));
        $this->assertSame(1, $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $deceased->id)->count()), 'the death record stays the last employment; nothing else is written');

        // A former Employee separated any other way is still rehired through the canonical path. (Whether an ARCHIVED
        // identity should gate a new employment is left to the owner: Phase 8A.13 keeps the identity record and the
        // employment lifecycle independent -- HrEmployeeRehireTest pins that contract unchanged.)
        $former = $this->createEmployee($this->school, ['user_id' => null]);
        $this->createEmploymentRecord($former, ['starts_on' => '2024-01-01', 'ends_on' => '2025-12-31', 'status' => 'separated']);
        $rehired = app(EmployeeLifecycleService::class)->rehire($this->inSchool(fn () => $former->fresh()), ['employment_type' => 'permanent', 'starts_on' => '2027-01-04'], $this->hr);
        $this->assertSame('active', $rehired->status);
        $this->assertSame($former->id, $rehired->employee_id, 'the same Employee identity, never a new one');
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('a new employment was recorded for an Employee recorded deceased');
        } catch (InvalidEmploymentTermsException $e) {
            $this->assertStringContainsString('death', $e->getMessage());
        }
    }

    #[Test]
    public function only_the_employment_end_records_a_never_effective_placement(): void
    {
        // Second security review (2026-11-05): the widened CHECK must not let any other writer blank an effective placement.
        [$employment, $source] = $this->placed();
        try {
            app(EmployeeAssignmentService::class)->end($source, '2026-03-31', $this->hr);
            $this->fail('an effective assignment was ended before it started');
        } catch (AssignmentOutsideEmploymentRangeException) {
            $this->assertSame([$source->id => ['2026-04-01', null]], $this->ranges($employment));
        }
        foreach ([
            fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['ends_on' => '2026-03-31']),
            fn () => DB::table('employee_assignments')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employment_record_id' => $employment->id,
                'position_id' => $this->teacher->id, 'campus_id' => $this->a->id, 'is_primary' => false, 'starts_on' => '2026-12-01', 'ends_on' => '2026-11-30', 'created_at' => now(), 'updated_at' => now()]),
        ] as $raw) {
            try {
                DB::transaction(fn () => $this->inSchool($raw));
                $this->fail('the runtime role recorded a never-effective placement outside an employment end');
            } catch (QueryException $e) {
                $this->assertStringContainsString('hr_employee_assignments_never_effective', $e->getMessage());
            }
        }
    }
}
