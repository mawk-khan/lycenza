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
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function xrp_2_a_direct_web_reactivation_reaches_the_service_and_is_refused(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'other' => $other, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $service = $this->service();
        $refused = $service->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $service->deactivate($refused, $actor);
        $control = $service->create($school, $offering, $section, $other, null, $period, 2, $actor);
        $service->deactivate($control, $actor);
        $this->separate($school, $teacher);

        $web = fn (TimetableEntry $entry) => $this->actingAs($actor)->withSession(['active_school_id' => $school->id])->post("/app/timetable-schedule/{$entry->id}/activate");

        // The control proves the route reaches the service: the same request activates an employed teacher's entry.
        $web($control)->assertRedirect();
        $this->assertSame('active', $this->entryStatus($school, $control));
        $web($refused)->assertRedirect()->assertSessionHasErrors('refusal');
        $this->assertSame('inactive', $this->entryStatus($school, $refused));
    }

    #[Test]
    public function xrp_2_a_direct_api_reactivation_is_refused(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'other' => $other, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $service = $this->service();
        $refused = $service->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $service->deactivate($refused, $actor);
        $control = $service->create($school, $offering, $section, $other, null, $period, 2, $actor);
        $service->deactivate($control, $actor);
        $this->separate($school, $teacher);

        $api = fn (TimetableEntry $entry) => $this->withHeader('Authorization', 'Bearer '.$actor->createToken('t')->plainTextToken)
            ->postJson("/api/v1/schools/{$school->id}/timetable-entries/{$entry->id}/activate");

        $api($control)->assertOk();
        $api($refused)->assertStatus(422);
        $this->assertSame('inactive', $this->entryStatus($school, $refused));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function employmentStates(): array
    {
        return [
            'active' => [['status' => 'active', 'starts_on' => '2020-01-01', 'ends_on' => null], true],
            'on notice' => [['status' => 'notice_period', 'starts_on' => '2020-01-01', 'ends_on' => null], true],
            'pre-joining' => [['status' => 'pre_joining', 'starts_on' => '+5 days', 'ends_on' => null], false],
            'starts in the future' => [['status' => 'active', 'starts_on' => '+5 days', 'ends_on' => null], false],
            'draft' => [['status' => 'draft', 'starts_on' => '2020-01-01', 'ends_on' => null], false],
            'completed' => [['status' => 'separated', 'starts_on' => '2020-01-01', 'ends_on' => '-2 days'], false],
        ];
    }

    /** @param array<string, mixed> $state */
    #[Test]
    #[DataProvider('employmentStates')]
    public function reactivation_follows_the_teachers_employment_on_the_school_local_today(array $state, bool $allowed): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'other' => $employed, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $service = $this->service();
        $entry = $service->create($school, $offering, $section, $employed, null, $period, 1, $actor);
        $service->deactivate($entry, $actor);

        // Point the historical entry at a teacher in the state under test: only the runtime's own check decides.
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        foreach (['starts_on', 'ends_on'] as $date) {
            $state[$date] = isset($state[$date]) && str_starts_with((string) $state[$date], '2') === false ? now()->modify((string) $state[$date])->toDateString() : $state[$date];
        }
        $this->createEmploymentRecord($teacher, $state);
        app(TenantContext::class)->withSchool($school, fn () => DB::table('timetable_entries')->where('id', $entry->id)->update(['teacher_id' => $teacher->id]));

        if (! $allowed) {
            $this->expectException(TeacherNotAvailableException::class);
        }
        $service->activate(app(TenantContext::class)->withSchool($school, fn () => $entry->fresh()), $actor);
        $this->assertSame('active', $this->entryStatus($school, $entry));
    }

    #[Test]
    public function a_rehired_teacher_reactivates_under_the_new_employment_and_the_entry_is_never_rewritten(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);
        $this->separate($school, $teacher);
        $before = (array) app(TenantContext::class)->withSchool($school, fn () => DB::table('timetable_entries')->where('id', $entry->id)->first());

        try {
            $this->service()->activate($entry, $actor);
            $this->fail('reactivated before the rehire');
        } catch (TeacherNotAvailableException) {
            $this->assertSame($before, (array) app(TenantContext::class)->withSchool($school, fn () => DB::table('timetable_entries')->where('id', $entry->id)->first()), 'a refusal changes nothing');
        }

        $this->createEmploymentRecord($teacher, ['status' => 'active', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null]);
        $this->service()->activate(app(TenantContext::class)->withSchool($school, fn () => $entry->fresh()), $actor);
        $this->assertSame('active', $this->entryStatus($school, $entry));
    }

    #[Test]
    public function another_schools_teacher_is_refused_and_another_schools_entry_is_not_found(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->world();
        $actor = $this->fullTimetableActor($school);
        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);
        $other = $this->createSchool();
        $foreign = $this->createEmployedEmployee($other, ['record_status' => 'active']);
        $outsider = $this->fullTimetableActor($other);

        try {
            $this->service()->create($school, $offering, $section, $foreign, null, $period, 2, $actor);
            $this->fail('a teacher of another School was scheduled');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }
        $this->withHeader('Authorization', 'Bearer '.$outsider->createToken('t')->plainTextToken)
            ->postJson("/api/v1/schools/{$other->id}/timetable-entries/{$entry->id}/activate")->assertNotFound();
        $this->assertSame('inactive', $this->entryStatus($school, $entry));
    }
}
