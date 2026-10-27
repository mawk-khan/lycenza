<?php

namespace Tests\Feature\DataHealth;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\DataHealth\DataHealthRegistry;
use App\Support\DataHealth\DataHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * SIS 8.1 (SB-02): register marks outside the placement they were taken
 * against are flagged, one finding per placement -- and valid history is
 * never a finding: an ended placement whose marks are inside its dates, a
 * placement completed in a closed year, and an ADR 0078 pre-start
 * cancellation (`ends_on = starts_on - 1`).
 */
class DataHealthPlacementHistoryTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private const string MONDAY_1 = '2026-06-08';

    private const string MONDAY_2 = '2026-06-15';

    private function proof(User $user): FreshMfaProof
    {
        return new FreshMfaProof($user->id, CarbonImmutable::now());
    }

    private function viewer(School $school): User
    {
        return $this->createUserWithCapabilities($school, [
            'students.view', 'enrollments.view', 'academics.years.view', 'academics.structure.view',
            'timetable.schedule.view', 'teaching.assignments.view', 'attendance.view', 'finance.charges.view',
        ]);
    }

    /** @param  array<string, string>  $statuses */
    private function register(array $w, string $date, array $statuses): AttendanceSession
    {
        $service = app(AttendanceSubmissionService::class);

        return $this->inSchool($w['school'], fn () => $service->guarded(fn () => DB::transaction(
            fn () => $service->submit($w['school'], $w['entry']->id, $date, $this->registerPayload($statuses), $w['actor']),
        )));
    }

    /** @return array<string, list<array<string, mixed>>> every check's findings, by code */
    private function everyCheck(School $school): array
    {
        $viewer = $this->viewer($school);
        $out = [];
        foreach (array_keys(app(DataHealthRegistry::class)->all()) as $code) {
            $out[$code] = app(DataHealthService::class)->findings($school, $code, $viewer, 1)['findings']->items();
        }

        return $out;
    }

    #[Test]
    public function marks_after_a_back_dated_end_are_flagged_once_per_placement_and_valid_history_is_not(): void
    {
        $w = $this->attendanceWorld();
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage', 'enrollments.view', 'enrollments.manage']);
        $left = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $stayed = $this->enrollStudent($w['section'], '2', '2026-06-01');
        $endedCleanly = $this->enrollStudent($w['section'], '3', '2026-06-01');
        $enrollments = app(StudentEnrollmentService::class);
        $this->register($w, self::MONDAY_1, [$left->id => 'present', $stayed->id => 'present', $endedCleanly->id => 'absent']);
        // Ended after its last mark: valid history, never a finding.
        $enrollments->withdraw($endedCleanly, '2026-06-12', 'guardian_request', $admin, $this->proof($admin));
        $this->register($w, self::MONDAY_2, [$left->id => 'absent', $stayed->id => 'present']);
        // Back-dated: recorded as ended on 2026-06-03, after two registers already marked them.
        $enrollments->withdraw($left, '2026-06-03', 'guardian_request', $admin, $this->proof($admin));

        $findings = $this->everyCheck($w['school'])['attendance.recorded_outside_placement'];

        $this->assertSame([$left->id], array_column($findings, 'entityId'), 'one finding per placement, never one per mark');
        $this->assertStringContainsString('2 register marks outside it, 2026-06-08 to 2026-06-15', $findings[0]['detail']);
        $this->assertStringContainsString('placement 2026-06-01 to 2026-06-03 (withdrawn)', $findings[0]['detail']);
        $this->assertContains(
            "/app/attendance/students/{$left->student_id}?from=2026-06-08&to=2026-06-15",
            array_column($findings[0]['links'], 'url'),
        );
    }

    #[Test]
    public function closed_year_completions_and_pre_start_cancellations_are_never_findings(): void
    {
        $w = $this->attendanceWorld();
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage', 'enrollments.view', 'enrollments.manage']);
        $closed = $this->createAcademicYear($w['school'], ['code' => 'C'.Str::random(5), 'starts_on' => '2025-06-01', 'ends_on' => '2026-05-31', 'status' => 'closed']);
        $closedSection = $this->createSection($closed, $w['campus'], $w['grade'], ['code' => 'CL'.Str::random(3)]);
        $history = $this->createStudentEnrollment($this->createStudent($w['school'], ['status' => 'inactive']), $closedSection, [
            'status' => 'completed', 'starts_on' => '2025-06-01', 'ends_on' => '2026-05-31',
        ]);

        $start = CarbonImmutable::now()->addDays(14)->toDateString();
        $future = $this->enrollStudent($w['section'], '9', $start);
        $cancelled = app(StudentEnrollmentService::class)->cancelBeforeStart($future, 'did_not_join', $admin, $this->proof($admin));
        $this->assertSame(
            CarbonImmutable::parse($start)->subDay()->toDateString(),
            $this->inSchool($w['school'], fn () => StudentEnrollment::query()->whereKey($cancelled->id)->value('ends_on'))->toDateString(),
            'the ADR 0078 empty interval',
        );

        $ids = [$history->id, $cancelled->id];
        foreach ($this->everyCheck($w['school']) as $code => $rows) {
            $this->assertSame([], array_values(array_intersect($ids, array_column($rows, 'entityId'))), "{$code} flags valid history");
        }
    }
}
