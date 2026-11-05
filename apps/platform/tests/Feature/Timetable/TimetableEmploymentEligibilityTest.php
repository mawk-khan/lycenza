<?php

namespace Tests\Feature\Timetable;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Timetable\Application\Exceptions\TeacherNotAvailableException;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * HR.R1-C2 -- XRP-1 (reactivating a weekly entry bypassed the current-
 * employment check create() and update() apply) and XRP-2 (no test pinned
 * the separated-teacher refusal on any Timetable operation). The refusal
 * comes from HR's EmploymentCoverage on the School-local today, never the
 * identity flag; the historical entry is never changed by a refusal.
 */
class TimetableEmploymentEligibilityTest extends TestCase
{
    use CreatesTimetableFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployedEmployee($school, ['record_status' => 'active']);
        $other = $this->createEmployedEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);

        return compact('school', 'offering', 'section', 'teacher', 'other', 'period');
    }

    private function service(): TimetableScheduleService
    {
        return app(TimetableScheduleService::class);
    }

    private function separate(School $school, Employee $teacher): void
    {
        $employment = app(TenantContext::class)->withSchool($school, fn () => EmploymentRecord::query()->where('employee_id', $teacher->id)->firstOrFail());
        app(EmploymentService::class)->end($employment, now()->subDays(2)->toDateString(), $this->fullHrActor($school));
    }

    private function entryStatus(School $school, TimetableEntry $entry): string
    {
        return app(TenantContext::class)->withSchool($school, fn () => (string) DB::table('timetable_entries')->where('id', $entry->id)->value('status'));
    }

    #[Test]
    public function xrp_1_a_deactivated_entry_of_a_teacher_whose_employment_ended_is_never_reactivated(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);

        // The teacher's Employee identity stays `active`: only the employment ended.
        $this->separate($school, $teacher);
        $this->assertSame('active', app(TenantContext::class)->withSchool($school, fn () => $teacher->fresh()->record_status));

        try {
            $this->service()->activate(app(TenantContext::class)->withSchool($school, fn () => $entry->fresh()), $actor);
            $this->fail('a separated teacher\'s weekly entry was put back into service');
        } catch (TeacherNotAvailableException) {
            $this->assertSame('inactive', $this->entryStatus($school, $entry), 'the historical entry is untouched');
            $this->assertSame($teacher->id, app(TenantContext::class)->withSchool($school, fn () => (string) DB::table('timetable_entries')->where('id', $entry->id)->value('teacher_id')));
        }
    }

    #[Test]
    public function xrp_1_an_entry_of_a_currently_employed_teacher_reactivates(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);
        $this->service()->activate(app(TenantContext::class)->withSchool($school, fn () => $entry->fresh()), $actor);
        $this->assertSame('active', $this->entryStatus($school, $entry));
    }

    #[Test]
    public function xrp_2_create_and_update_refuse_a_separated_teacher_too(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'other' => $other, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $entry = $this->service()->create($school, $offering, $section, $other, null, $period, 1, $actor);
        $this->separate($school, $teacher);

        try {
            $this->service()->create($school, $offering, $section, $teacher, null, $period, 2, $actor);
            $this->fail('a new entry was created for a separated teacher');
        } catch (TeacherNotAvailableException) {
        }
        try {
            $this->service()->update(app(TenantContext::class)->withSchool($school, fn () => $entry->fresh()), $offering, $section, $teacher, null, $period, 1, $actor);
            $this->fail('an entry was moved to a separated teacher');
        } catch (TeacherNotAvailableException) {
            $this->assertSame($other->id, app(TenantContext::class)->withSchool($school, fn () => (string) DB::table('timetable_entries')->where('id', $entry->id)->value('teacher_id')));
        }
    }

    #[Test]
    public function xrp_2_a_direct_web_reactivation_of_a_separated_teacher_is_refused(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);
        $this->separate($school, $teacher);

        // The one reactivation route (there is no API activate): a forged POST still reaches the service check.
        $this->actingAs($actor)->post("/app/schools/{$school->id}/activate");
        $this->post("/app/timetable-schedule/{$entry->id}/activate");
        $this->assertSame('inactive', $this->entryStatus($school, $entry));
    }
}
