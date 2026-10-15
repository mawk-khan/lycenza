<?php

namespace Tests\Feature\App;

use App\Domain\Attendance\Application\AttendanceExceptionReadService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * V11.2 (ADR 0075): /app/attendance/exceptions -- one School day's
 * absent/late/excused records across submitted registers, under
 * attendance.view. Individual records only (no rate, no tally); never
 * another School's; never `present`.
 */
class AttendanceExceptionListUiTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    /**
     * A submitted Monday register: roll 1 present, roll 2 absent, roll 3 late.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function submittedRegister(): array
    {
        $w = $this->attendanceWorld();
        $present = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $absent = $this->enrollStudent($w['section'], '2', '2026-06-01');
        $late = $this->enrollStudent($w['section'], '3', '2026-06-01');

        $this->actor($w)->post('/app/attendance', [
            'timetable_entry_id' => $w['entry']->id,
            'attendance_date' => self::MONDAY,
            'records' => $this->registerPayload([$present->id => 'present', $absent->id => 'absent', $late->id => 'late']),
        ])->assertRedirect();

        return [$w, ['present' => $present->student_id, 'absent' => $absent->student_id, 'late' => $late->student_id]];
    }

    /** @return array<string, mixed> */
    private function props(array $w, string $query, ?object $user = null): array
    {
        return $this->actor($w, $user)->get('/app/attendance/exceptions?'.$query)->assertOk()->viewData('page')['props'];
    }

    #[Test]
    public function the_day_lists_absent_and_late_records_but_never_present_ones(): void
    {
        [$w, $students] = $this->submittedRegister();
        $viewer = $this->createUserWithCapabilities($w['school'], ['attendance.view']);

        $props = $this->props($w, 'date='.self::MONDAY, $viewer);
        $rows = collect($props['records']['data']);

        $this->assertEqualsCanonicalizing(['absent', 'late'], $rows->pluck('status')->all());
        $this->assertEqualsCanonicalizing([$students['absent'], $students['late']], $rows->pluck('studentId')->all());
        $this->assertNotContains($students['present'], $rows->pluck('studentId')->all());
        $this->assertSame(2, $props['records']['total']);
        $this->assertSame('09:00:00', $rows->first()['periodStartTime'], 'The register snapshot, not the current Period.');
        $this->assertSame([$w['section']->id], array_column($props['sectionOptions'], 'id'));
        $this->assertFalse($props['canViewStudents']);
        // Name columns only: no date of birth anywhere in the payload.
        $this->assertStringNotContainsString('date_of_birth', json_encode($props));
        $this->assertStringNotContainsString('dateOfBirth', json_encode($props));
    }

    #[Test]
    public function status_and_section_filters_narrow_and_other_days_are_empty(): void
    {
        [$w, $students] = $this->submittedRegister();

        $this->assertSame(
            [$students['late']],
            array_column($this->props($w, 'date='.self::MONDAY.'&status=late')['records']['data'], 'studentId'),
        );
        $this->assertSame([], $this->props($w, 'date='.self::MONDAY.'&status=excused')['records']['data']);
        $this->assertCount(2, $this->props($w, 'date='.self::MONDAY.'&section_id='.$w['section']->id)['records']['data']);

        $otherDay = $this->props($w, 'date=2026-09-01');
        $this->assertSame([], $otherDay['records']['data']);
        $this->assertSame([], $otherDay['sectionOptions']);
    }

    #[Test]
    public function invalid_input_is_a_validation_error_never_a_500(): void
    {
        [$w] = $this->submittedRegister();

        foreach (['date=31-08-2026', 'status=present', 'status=sick', 'section_id=abc'] as $query) {
            $this->actor($w)->get('/app/attendance/exceptions?'.$query)->assertSessionHasErrors();
        }
    }

    #[Test]
    public function another_schools_records_never_appear(): void
    {
        [$w] = $this->submittedRegister();
        [$other, $otherStudents] = $this->submittedRegister();

        $mine = $this->props($w, 'date='.self::MONDAY);
        $ids = array_column($mine['records']['data'], 'studentId');
        $this->assertNotContains($otherStudents['absent'], $ids);
        $this->assertNotContains($otherStudents['late'], $ids);
        // Even with the other School's Section id as a filter.
        $this->assertSame([], $this->props($w, 'date='.self::MONDAY.'&section_id='.$other['section']->id)['records']['data']);
    }

    #[Test]
    public function without_attendance_view_it_is_forbidden_including_for_teachers(): void
    {
        [$w] = $this->submittedRegister();
        $studentsOnly = $this->createUserWithCapabilities($w['school'], ['students.view']);
        $teacherCapability = $this->createUserWithCapabilities($w['school'], ['attendance.teacher']);

        $this->actor($w, $studentsOnly)->get('/app/attendance/exceptions?date='.self::MONDAY)->assertForbidden();
        $this->actor($w, $teacherCapability)->get('/app/attendance/exceptions?date='.self::MONDAY)->assertForbidden();
    }

    #[Test]
    public function rows_link_to_the_student_only_for_students_view_holders(): void
    {
        [$w] = $this->submittedRegister();
        $both = $this->createUserWithCapabilities($w['school'], ['attendance.view', 'students.view']);

        $this->assertTrue($this->props($w, 'date='.self::MONDAY, $both)['canViewStudents']);
    }

    #[Test]
    public function the_admin_registers_page_links_here_and_my_attendance_never_does(): void
    {
        [$w] = $this->submittedRegister();

        $this->assertSame(
            '/app/attendance/exceptions',
            $this->actor($w)->get('/app/attendance')->assertOk()->viewData('page')['props']['exceptionsUrl'],
        );
        $this->assertSame(['absent', 'late', 'excused'], AttendanceExceptionReadService::EXCEPTION_STATUSES);
        // The owned teacher surface renders the same page but never passes the link.
        $this->assertStringNotContainsString(
            'exceptionsUrl',
            (string) file_get_contents(app_path('Http/Controllers/App/Attendance/MyAttendanceController.php')),
        );
    }
}
