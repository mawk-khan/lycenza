<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceDateInFutureException;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * UAT-C1 (UAT-ATT-10): "not in the future" is decided by the School's own
 * calendar date, never the UTC application date. Local UAT saw today's
 * register refused at 01:34 IST because UTC was still the day before.
 * Every clock below is fixed with travelTo(); MONDAY is 2026-08-31.
 */
class AttendanceSchoolDateTest extends TestCase
{
    use CreatesAttendanceFixtures;

    /** @return array{0: array<string, mixed>, 1: string} the world and its one placement id */
    private function world(string $timezone): array
    {
        $w = $this->attendanceWorld();
        $w['school']->forceFill(['timezone' => $timezone])->save();
        $enrollment = $this->enrollStudent($w['section'], '1', '2026-06-01');

        return [$w, $enrollment->id];
    }

    private function submitMonday(array $w, string $enrollmentId): AttendanceSession
    {
        $service = app(AttendanceSubmissionService::class);

        return $this->inSchool($w['school'], fn () => $service->guarded(fn () => DB::transaction(fn () => $service->submit(
            $w['school'], $w['entry']->id, self::MONDAY, $this->registerPayload([$enrollmentId => 'present']), $w['actor'],
        ))));
    }

    #[Test]
    public function todays_register_is_accepted_when_the_school_day_has_begun_but_utc_is_still_yesterday(): void
    {
        [$w, $enrollmentId] = $this->world('Asia/Kolkata');

        // Sunday 2026-08-30 19:00 UTC = Monday 2026-08-31 00:30 in Asia/Kolkata.
        $this->travelTo(CarbonImmutable::parse('2026-08-30T19:00:00Z'));

        $this->assertSame(self::MONDAY, $this->submitMonday($w, $enrollmentId)->attendance_date->toDateString());
    }

    #[Test]
    public function tomorrows_register_in_school_time_is_refused(): void
    {
        [$w, $enrollmentId] = $this->world('Asia/Kolkata');

        // Sunday 2026-08-30 18:00 UTC = Sunday 23:30 in Asia/Kolkata: Monday is tomorrow.
        $this->travelTo(CarbonImmutable::parse('2026-08-30T18:00:00Z'));

        $this->expectException(AttendanceDateInFutureException::class);
        $this->submitMonday($w, $enrollmentId);
    }

    #[Test]
    public function a_school_west_of_utc_cannot_record_its_tomorrow_even_when_utc_has_reached_it(): void
    {
        [$w, $enrollmentId] = $this->world('America/Los_Angeles');

        // Monday 2026-08-31 03:00 UTC = Sunday 20:00 in Los Angeles.
        $this->travelTo(CarbonImmutable::parse('2026-08-31T03:00:00Z'));

        $this->expectException(AttendanceDateInFutureException::class);
        $this->submitMonday($w, $enrollmentId);
    }

    #[Test]
    public function each_school_is_judged_by_its_own_timezone(): void
    {
        [$kolkata, $kolkataEnrollment] = $this->world('Asia/Kolkata');
        [$angeles, $angelesEnrollment] = $this->world('America/Los_Angeles');

        // Monday 2026-08-31 03:00 UTC: Monday 08:30 in Kolkata, Sunday 20:00 in Los Angeles.
        $this->travelTo(CarbonImmutable::parse('2026-08-31T03:00:00Z'));

        $this->assertNotNull($this->submitMonday($kolkata, $kolkataEnrollment)->id);

        $this->expectException(AttendanceDateInFutureException::class);
        $this->submitMonday($angeles, $angelesEnrollment);
    }

    #[Test]
    public function the_take_page_defaults_to_the_schools_today_and_refuses_its_tomorrow_as_a_form_error(): void
    {
        [$w, $enrollmentId] = $this->world('Asia/Kolkata');
        $this->actingAs($w['actor'])->post("/app/schools/{$w['school']->id}/activate");

        // Sunday 2026-08-30 19:00 UTC = Monday 00:30 in Asia/Kolkata.
        $this->travelTo(CarbonImmutable::parse('2026-08-30T19:00:00Z'));
        $this->assertSame(self::MONDAY, $this->get('/app/attendance/take')->assertOk()->viewData('page')['props']['attendanceDate']);

        // Sunday 18:00 UTC = Sunday 23:30 in Asia/Kolkata: Monday's register is not yet allowed.
        $this->travelTo(CarbonImmutable::parse('2026-08-30T18:00:00Z'));
        $response = $this->post('/app/attendance', [
            'timetable_entry_id' => $w['entry']->id,
            'attendance_date' => self::MONDAY,
            'records' => $this->registerPayload([$enrollmentId => 'present']),
        ]);
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }

    #[Test]
    public function recording_attendance_still_requires_the_manage_capability(): void
    {
        [$w, $enrollmentId] = $this->world('Asia/Kolkata');
        $viewer = $this->createUserWithCapabilities($w['school'], ['attendance.view']);
        $this->actingAs($viewer)->post("/app/schools/{$w['school']->id}/activate");
        $this->travelTo(CarbonImmutable::parse('2026-08-31T06:00:00Z'));

        $this->post('/app/attendance', [
            'timetable_entry_id' => $w['entry']->id,
            'attendance_date' => self::MONDAY,
            'records' => $this->registerPayload([$enrollmentId => 'present']),
        ])->assertForbidden();
    }
}
