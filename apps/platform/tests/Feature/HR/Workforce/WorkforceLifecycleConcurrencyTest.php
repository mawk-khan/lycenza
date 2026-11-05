<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Models\School;
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
}
