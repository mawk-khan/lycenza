<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * HR.R1-C2, two real PostgreSQL sessions (the holder held open, the
 * contender observed blocked in pg_stat_activity): ending an employment
 * serializes with a future placement change (EmployeePlacementLock) and
 * with a Timetable reactivation (EmploymentCoverage FOR SHARE vs the end's
 * FOR UPDATE) -- whichever commits first, the final state never holds an
 * effective placement or a newly reactivated duty outside the employment.
 * HR.R1-C2 gap closure: the same proof for a whole-School assignment, a
 * primary correction and a reporting line (whose placement lock is FOR SHARE
 * like the end's, so the employment row is what serializes them), and a
 * retried end.
 */
class WorkforceLifecycleConcurrencyTest extends TestCase
{
    use CreatesTimetableFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->deleteSchoolAsAdmin($this->school);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/hr-lifecycle-op.php', $this->school->id, ...$args];
    }

    /** @return array{0: EmploymentRecord, 1: EmployeeAssignment, 2: string, 3: string} employment, primary, position, other campus */
    private function placed(): array
    {
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $a = $this->createCampus($this->school);
        $b = $this->createCampus($this->school);
        $position = $this->createPosition($this->school, ['status' => 'active']);
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-04-01', 'ends_on' => null, 'status' => 'active']);
        $primary = $this->createEmployeeAssignment($employment, $position, ['campus_id' => $a->id, 'is_primary' => true, 'starts_on' => '2026-04-01', 'ends_on' => null]);

        return [$employment, $primary, $position->id, $b->id];
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    private function ranges(EmploymentRecord $employment): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => DB::table('employee_assignments')->where('employment_record_id', $employment->id)->orderBy('starts_on')->get()
            ->map(fn ($a) => [(string) $a->starts_on, $a->ends_on === null ? null : (string) $a->ends_on])->all());
    }

    #[Test]
    public function an_end_held_open_refuses_the_waiting_future_transfer(): void
    {
        [$employment, $primary, $position, $b] = $this->placed();
        $hr = $this->fullHrActor($this->school);
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();
        $transferOn = CarbonImmutable::now('Asia/Kolkata')->addDays(20)->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('end', $employment->id, $lastDay, $hr->id), $this->op('transfer', $primary->id, $transferOn, $position, $b, $hr->id));

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('rejected:App\\Domain\\HR\\Application\\Exceptions\\InvalidPlacementChangeException', $contender, 'the transfer waited, then saw the ended employment');
        $this->assertSame([['2026-04-01', $lastDay]], $this->ranges($employment), 'one placement, ending on the last day');
    }

    #[Test]
    public function a_future_transfer_held_open_is_cancelled_by_the_waiting_end(): void
    {
        [$employment, $primary, $position, $b] = $this->placed();
        $hr = $this->fullHrActor($this->school);
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();
        $transferOn = CarbonImmutable::now('Asia/Kolkata')->addDays(20)->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('transfer', $primary->id, $transferOn, $position, $b, $hr->id), $this->op('end', $employment->id, $lastDay, $hr->id));

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:separated', $contender, 'the end waited for the transfer, then disposed of it');
        $dayBefore = CarbonImmutable::parse($transferOn)->subDay()->toDateString();
        $this->assertSame([['2026-04-01', $lastDay], [$transferOn, $dayBefore]], $this->ranges($employment), 'the planned transfer never takes effect');
    }

    #[Test]
    public function an_end_held_open_refuses_the_waiting_timetable_reactivation(): void
    {
        $this->school = $this->createSchool();
        $campus = $this->createCampus($this->school);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployedEmployee($this->school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($this->school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);
        $actor = $this->fullTimetableActor($this->school);
        $entry = app(TimetableScheduleService::class)->create($this->school, $offering, $section, $teacher, null, $period, 1, $actor);
        app(TimetableScheduleService::class)->deactivate($entry, $actor);
        $employment = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $teacher->id)->firstOrFail());
        $hr = $this->fullHrActor($this->school);
        $yesterday = CarbonImmutable::now()->subDay()->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('end', $employment->id, $yesterday, $hr->id), $this->op('activate', $entry->id, $actor->id));

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('rejected:App\\Domain\\Timetable\\Application\\Exceptions\\TeacherNotAvailableException', $contender);
        $this->assertSame('inactive', app(TenantContext::class)->withSchool($this->school, fn () => (string) DB::table('timetable_entries')->where('id', $entry->id)->value('status')));
    }

    #[Test]
    public function an_end_held_open_refuses_the_waiting_whole_school_assignment(): void
    {
        // The whole-School placement lock is FOR SHARE on both sides: only the employment row serializes this pair.
        [$employment, , $position, $b] = $this->placed();
        $hr = $this->fullHrActor($this->school);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('end', $employment->id, $lastDay, $hr->id), $this->op('assign', $employment->id, $today, $position, $b, $hr->id));

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('rejected:App\\Domain\\HR\\Application\\Exceptions\\AssignmentOutsideEmploymentRangeException', $contender, 'no open placement on an ended employment');
        $this->assertSame([['2026-04-01', $lastDay]], $this->ranges($employment));
    }

    #[Test]
    public function an_assignment_held_open_is_clipped_by_the_waiting_end(): void
    {
        [$employment, , $position, $b] = $this->placed();
        $hr = $this->fullHrActor($this->school);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('assign', $employment->id, $today, $position, $b, $hr->id), $this->op('end', $employment->id, $lastDay, $hr->id));

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:separated', $contender);
        $this->assertSame([['2026-04-01', $lastDay], [$today, $lastDay]], $this->ranges($employment));
    }

    #[Test]
    public function an_end_held_open_refuses_the_waiting_primary_correction_of_a_placement_that_never_took_effect(): void
    {
        [$employment, , $position, $b] = $this->placed();
        $hr = $this->fullHrActor($this->school);
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();
        $plannedOn = CarbonImmutable::now('Asia/Kolkata')->addDays(20);
        $planned = $this->createEmployeeAssignment($employment, $this->position($position), ['campus_id' => $b, 'is_primary' => false, 'starts_on' => $plannedOn->toDateString(), 'ends_on' => null]);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('end', $employment->id, $lastDay, $hr->id), $this->op('primary', $planned->id, $hr->id));

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('rejected:App\\Domain\\HR\\Application\\Exceptions\\PlacementChangeConflictException', $contender);
        $this->assertSame([['2026-04-01', $lastDay], [$plannedOn->toDateString(), $plannedOn->subDay()->toDateString()]], $this->ranges($employment));
        $this->assertFalse((bool) app(TenantContext::class)->withSchool($this->school, fn () => DB::table('employee_assignments')->where('id', $planned->id)->value('is_primary')));
    }

    #[Test]
    public function a_primary_correction_held_open_is_kept_by_the_waiting_end(): void
    {
        [$employment, , $position, $b] = $this->placed();
        $hr = $this->fullHrActor($this->school);
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();
        $second = $this->createEmployeeAssignment($employment, $this->position($position), ['campus_id' => $b, 'is_primary' => false, 'starts_on' => '2026-06-01', 'ends_on' => null]);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('primary', $second->id, $hr->id), $this->op('end', $employment->id, $lastDay, $hr->id));

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:separated', $contender);
        $this->assertSame([['2026-04-01', $lastDay], ['2026-06-01', $lastDay]], $this->ranges($employment));
        $this->assertTrue((bool) app(TenantContext::class)->withSchool($this->school, fn () => DB::table('employee_assignments')->where('id', $second->id)->value('is_primary')));
    }

    private function position(string $id): Position
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Position::query()->findOrFail($id));
    }

    /** @return array{0: EmploymentRecord, 1: EmploymentRecord} subordinate, manager */
    private function subordinateAndManager(): array
    {
        [$subordinate] = $this->placed();
        $manager = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-04-01', 'ends_on' => null, 'status' => 'active']);

        return [$subordinate, $manager];
    }

    /** @return list<object> */
    private function lines(EmploymentRecord $subordinate): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => DB::table('reporting_relationships')->where('subordinate_employment_id', $subordinate->id)->get()->all());
    }

    #[Test]
    public function an_end_held_open_refuses_the_waiting_reporting_line(): void
    {
        [$subordinate, $manager] = $this->subordinateAndManager();
        $hr = $this->fullHrActor($this->school);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('end', $subordinate->id, $today, $hr->id), $this->op('report', $subordinate->id, $manager->id, CarbonImmutable::now('Asia/Kolkata')->addDay()->toDateString(), $hr->id));

        $this->assertSame('ok:separated', $holder);
        $this->assertStringStartsWith('rejected:', $contender, 'the line waited for the employment, then saw it ended');
        $this->assertSame([], $this->lines($subordinate));
    }

    #[Test]
    public function a_reporting_line_held_open_is_closed_by_the_waiting_end(): void
    {
        [$subordinate, $manager] = $this->subordinateAndManager();
        $hr = $this->fullHrActor($this->school);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('report', $subordinate->id, $manager->id, $today, $hr->id), $this->op('end', $subordinate->id, $lastDay, $hr->id));

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:separated', $contender);
        $lines = $this->lines($subordinate);
        $this->assertCount(1, $lines, 'closed, never deleted or replaced');
        $this->assertSame($lastDay, (string) $lines[0]->ends_on);
    }

    #[Test]
    public function a_retried_end_changes_nothing_and_is_audited_once(): void
    {
        [$employment, , $position, $b] = $this->placed();
        $hr = $this->fullHrActor($this->school);
        $lastDay = CarbonImmutable::now('Asia/Kolkata')->addDays(5)->toDateString();
        $plannedOn = CarbonImmutable::now('Asia/Kolkata')->addDays(20);
        $this->createEmployeeAssignment($employment, $this->position($position), ['campus_id' => $b, 'is_primary' => false, 'starts_on' => $plannedOn->toDateString(), 'ends_on' => null]);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('end', $employment->id, $lastDay, $hr->id), $this->op('end', $employment->id, $lastDay, $hr->id));

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('rejected:App\\Domain\\HR\\Application\\Exceptions\\EmploymentAlreadyEndedException', $contender);
        $this->assertSame([['2026-04-01', $lastDay], [$plannedOn->toDateString(), $plannedOn->subDay()->toDateString()]], $this->ranges($employment));
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->where('subject_id', $employment->id)->count()));
    }

    #[Test]
    public function a_reactivation_held_open_is_ordered_before_the_waiting_end(): void
    {
        // Ordered first, the duty was valid when it was activated; the end then proceeds exactly as for any live
        // entry, and the separation obligations surface it. Timetable data is never changed by an employment end.
        $this->school = $this->createSchool();
        $campus = $this->createCampus($this->school);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployedEmployee($this->school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($this->school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);
        $actor = $this->fullTimetableActor($this->school);
        $entry = app(TimetableScheduleService::class)->create($this->school, $offering, $section, $teacher, null, $period, 1, $actor);
        app(TimetableScheduleService::class)->deactivate($entry, $actor);
        $employment = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $teacher->id)->firstOrFail());
        $hr = $this->fullHrActor($this->school);
        $yesterday = CarbonImmutable::now()->subDay()->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('activate', $entry->id, $actor->id), $this->op('end', $employment->id, $yesterday, $hr->id));

        $this->assertSame('ok:active', $holder);
        $this->assertSame('ok:separated', $contender, 'the end waited for the reactivation (FOR SHARE vs FOR UPDATE)');
    }
}
