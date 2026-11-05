<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Application\CurrentEmployeeAssignments;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\AssignmentOutsideEmploymentRangeException;
use App\Domain\HR\Application\Exceptions\EmploymentAlreadyEndedException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentTermsException;
use App\Domain\HR\Application\Exceptions\PlacementChangeConflictException;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Application\Workforce\WorkforceHistoryReadService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
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

    /** @return array{0: EmploymentRecord, 1: EmployeeAssignment, 2: EmployeeAssignment} employment, the source, the transfer that never took effect */
    private function endedBeforeATransfer(): array
    {
        [$employment, $source] = $this->placed();
        $planned = $this->transfer($source, '2026-12-01');
        $this->end($employment, '2026-11-20');

        return [$employment, $source, $planned];
    }

    private function assertRuntimeRefuses(callable $write, string $because): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        try {
            DB::transaction(fn () => $this->inSchool($write));
            $this->fail("the runtime role was allowed to: {$because}");
        } catch (QueryException $e) {
            $this->assertStringContainsString('hr_employee_assignments_never_effective', $e->getMessage(), $because);
        }
    }

    #[Test]
    public function the_empty_interval_cannot_be_reached_through_starts_on(): void
    {
        // The guard must watch both columns: the CHECK admits `ends_on = starts_on - 1` however it was produced.
        [$employment, $source] = $this->placed();
        $this->inSchool(fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['ends_on' => '2026-10-31']));

        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['starts_on' => '2026-11-01']), 'blank a real placement through its start');
        $this->assertSame([$source->id => ['2026-04-01', '2026-10-31']], $this->ranges($employment));
    }

    #[Test]
    public function a_placement_that_never_took_effect_is_final(): void
    {
        [$employment, , $planned] = $this->endedBeforeATransfer();
        $before = $this->ranges($employment);

        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->where('id', $planned->id)->update(['ends_on' => null]), 'reopen it');
        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->where('id', $planned->id)->update(['ends_on' => '2026-12-31']), 'make it cover days');
        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->where('id', $planned->id)->update(['starts_on' => '2026-11-01', 'ends_on' => '2026-10-31']), 'move it');
        $this->assertSame($before, $this->ranges($employment));
    }

    #[Test]
    public function an_ended_employment_holds_no_placement_past_its_end(): void
    {
        [$employment, $source, $planned] = $this->endedBeforeATransfer();
        $row = ['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employment_record_id' => $employment->id, 'position_id' => $this->teacher->id,
            'campus_id' => $this->a->id, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()];

        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['ends_on' => null]), 'reopen a closed placement');
        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['ends_on' => '2026-11-21']), 'extend past the end');
        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->insert($row + ['starts_on' => '2026-11-10', 'ends_on' => null]), 'insert an open placement');
        $this->assertRuntimeRefuses(fn () => DB::table('employee_assignments')->insert($row + ['starts_on' => '2026-11-21', 'ends_on' => '2026-11-25']), 'insert past the end');
        // Inside the employment stays an ordinary correction: narrowing a closed placement.
        $this->inSchool(fn () => DB::table('employee_assignments')->where('id', $source->id)->update(['ends_on' => '2026-11-19']));
        $this->assertSame([$source->id => ['2026-04-01', '2026-11-19'], $planned->id => ['2026-12-01', '2026-11-30']], $this->ranges($employment));
    }

    #[Test]
    public function a_placement_that_never_took_effect_cannot_become_primary(): void
    {
        [$employment, $source] = $this->placed();
        $secondary = $this->createEmployeeAssignment($employment, $this->coordinator, ['campus_id' => $this->b->id, 'is_primary' => false, 'starts_on' => '2026-12-01', 'ends_on' => null]);
        $this->end($employment, '2026-11-20');

        try {
            app(EmployeeAssignmentService::class)->setPrimary($this->inSchool(fn () => $secondary->fresh()), $this->hr);
            $this->fail('a placement that never took effect became primary');
        } catch (PlacementChangeConflictException $e) {
            $this->assertSame('never_effective', $e->reason);
        }
        $this->assertTrue((bool) $this->inSchool(fn () => DB::table('employee_assignments')->where('id', $source->id)->value('is_primary')));
    }

    #[Test]
    public function the_staff_history_never_reports_a_cancelled_plan_as_a_completed_transfer(): void
    {
        [$employment, $source] = $this->placed();
        $this->transfer($source, '2026-11-16');
        $this->end($this->inSchool(fn () => $employment->fresh()), '2026-11-20');

        [$employment2, $source2] = $this->placed();
        $this->transfer($source2, '2026-12-01');
        $this->end($this->inSchool(fn () => $employment2->fresh()), '2026-11-20');

        $history = fn (EmploymentRecord $e) => app(WorkforceHistoryReadService::class)->forEmployee($this->school, $e->employee_id, $this->hr)[$e->id]['changes'];
        $this->assertFalse($history($employment)[0]['neverEffective'], 'it took effect on 16 November');
        $this->assertTrue($history($employment2)[0]['neverEffective'], 'the employment ended before it');
    }

    #[Test]
    public function a_back_dated_end_keeps_the_placement_it_overrides_in_the_audit(): void
    {
        // Security review 2026-11-05: a last day can precede a placement that had already started on the record. It
        // cannot stay outside the employment, and refusing would bring HRC-2 back, so the facts the row loses are kept.
        [$employment, $source] = $this->placed();
        $ran = $this->createEmployeeAssignment($employment, $this->coordinator, ['campus_id' => $this->b->id, 'is_primary' => false, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31']);
        $planned = $this->createEmployeeAssignment($employment, $this->coordinator, ['campus_id' => $this->b->id, 'is_primary' => false, 'starts_on' => '2026-12-01', 'ends_on' => null]);

        $this->end($employment, '2026-09-30');

        $this->assertSame([$source->id => ['2026-04-01', '2026-09-30'], $ran->id => ['2026-10-01', '2026-09-30'], $planned->id => ['2026-12-01', '2026-11-30']], $this->ranges($employment));
        $metadata = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->where('subject_id', $employment->id)->firstOrFail()->metadata);
        $this->assertSame([$ran->id => '2026-12-31'], $metadata['replacedAssignmentEnds']);
        $this->assertSame([$ran->id], $metadata['startedBeforeRecordedAssignmentIds'], 'only the placement that had started when the end was recorded');
    }

    #[Test]
    public function the_runtime_role_cannot_give_an_employee_recorded_deceased_another_employment(): void
    {
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->createEmploymentRecord($employee, ['starts_on' => '2024-01-01', 'ends_on' => '2026-01-31', 'status' => 'deceased']);

        try {
            DB::transaction(fn () => $this->inSchool(fn () => DB::table('employment_records')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employee->id,
                'employment_type' => 'permanent', 'starts_on' => '2026-11-01', 'ends_on' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()])));
            $this->fail('a new employment followed a recorded death');
        } catch (QueryException $e) {
            $this->assertStringContainsString('hr_employment_records_after_death', $e->getMessage());
        }
    }

    #[Test]
    public function ending_an_employment_stays_bounded_with_a_long_placement_history(): void
    {
        $queries = function (int $closed, int $planned): int {
            [$employment] = $this->placed('2010-01-01');
            for ($i = 0; $i < $closed; $i++) {
                $this->createEmployeeAssignment($employment, $this->coordinator, ['campus_id' => $this->b->id, 'is_primary' => false,
                    'starts_on' => CarbonImmutable::parse('2011-01-01')->addMonths($i)->toDateString(), 'ends_on' => CarbonImmutable::parse('2011-01-20')->addMonths($i)->toDateString()]);
            }
            for ($i = 0; $i < $planned; $i++) {
                $this->createEmployeeAssignment($employment, $this->coordinator, ['campus_id' => $this->b->id, 'is_primary' => false,
                    'starts_on' => CarbonImmutable::parse('2027-01-01')->addMonths($i)->toDateString(), 'ends_on' => null]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->end($employment, '2026-11-20');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            $ranges = array_values($this->ranges($employment));
            $this->assertCount($closed + $planned + 1, $ranges, 'nothing deleted');
            $this->assertSame(['2010-01-01', '2026-11-20'], $ranges[0]);
            foreach (array_slice($ranges, 1, $closed) as $untouched) {
                $this->assertLessThan('2026-11-20', $untouched[1], 'a closed placement keeps its end');
            }
            foreach (array_slice($ranges, $closed + 1) as $neverEffective) {
                $this->assertSame(CarbonImmutable::parse($neverEffective[0])->subDay()->toDateString(), $neverEffective[1]);
            }

            return $count;
        };

        $queries(1, 1); // warms the capability and School caches, so the two measured runs compare like with like
        $this->assertSame($queries(2, 1), $queries(120, 12), 'the end issues the same statements whatever the history size');
    }
}
