<?php

namespace Tests\Feature\Postgres;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * ORG.8E (ADR 0076 §63), rule 28: as the real runtime role, School B can
 * neither read nor write School A's `student_lifecycle_events` or
 * `attendance_record_corrections`, and no tenant context sees nothing.
 */
class LifecycleEvidenceRlsIsolationTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function asSchool(?School $school): void
    {
        DB::select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school?->id ?? '']);
    }

    #[Test]
    public function another_school_and_no_context_see_and_write_nothing(): void
    {
        $a = $this->attendanceWorld();
        $enrollment = $this->enrollStudent($a['section'], '1', '2026-06-01');
        $service = app(AttendanceSubmissionService::class);
        $session = $this->inSchool($a['school'], fn () => $service->guarded(fn () => DB::transaction(fn () => $service->submit(
            $a['school'], $a['entry']->id, self::MONDAY, $this->registerPayload([$enrollment->id => 'absent']), $a['actor'],
        ))));
        $record = $this->inSchool($a['school'], fn () => AttendanceRecord::query()->where('attendance_session_id', $session->id)->sole());
        app(AttendanceCorrectionService::class)->correct($a['school'], $record->id, 'absent', 'present', 'entered_in_error', $a['actor']);
        $this->changeStudentStatus($this->inSchool($a['school'], fn () => $enrollment->student()->firstOrFail()), 'inactive');
        $b = $this->createSchool();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);

        foreach ([$b, null] as $viewer) {
            $this->asSchool($viewer);
            $this->assertSame(0, (int) DB::selectOne('select count(*) as c from student_lifecycle_events')->c);
            $this->assertSame(0, (int) DB::selectOne('select count(*) as c from attendance_record_corrections')->c);
        }
        $this->asSchool($a['school']);
        $this->assertSame([1, 1], [(int) DB::selectOne('select count(*) as c from student_lifecycle_events')->c, (int) DB::selectOne('select count(*) as c from attendance_record_corrections')->c]);

        // School B cannot write a row naming School A.
        $this->asSchool($b);
        foreach ([
            fn () => DB::table('attendance_record_corrections')->insert(['id' => (string) Str::uuid7(), 'school_id' => $a['school']->id, 'attendance_record_id' => $record->id,
                'from_status' => 'present', 'to_status' => 'absent', 'reason_code' => 'entered_in_error', 'corrected_by_user_id' => $a['actor']->id]),
            fn () => DB::table('student_lifecycle_events')->insert(['id' => (string) Str::uuid7(), 'school_id' => $a['school']->id, 'student_id' => $enrollment->student_id,
                'action' => 'student_reactivated', 'reason_code' => 'returned_to_school', 'acted_by_user_id' => $a['actor']->id]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('A cross-School write must be refused.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('row-level security', $e->getMessage());
            }
        }
    }
}
