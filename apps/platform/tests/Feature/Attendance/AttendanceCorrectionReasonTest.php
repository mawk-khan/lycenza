<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceCorrectionReasonException;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceRecordCorrection;
use App\Models\SchoolAuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * ORG.8E (ADR 0076 §63, owner decision 8E-D2): a correction of an
 * already-recorded Student Attendance fact names a closed reason and leaves
 * an immutable `attendance_record_corrections` row (from, to, reason, who);
 * the database refuses a status change without it. First-time entry (a
 * submitted register) needs no reason. No approval and no time window; the
 * existing authorization tiers are unchanged (the reason never grants an
 * edit).
 */
class AttendanceCorrectionReasonTest extends TestCase
{
    use CreatesAttendanceFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $w['enrollment'] = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $service = app(AttendanceSubmissionService::class);
        // First-time entry: no reason anywhere.
        $w['session'] = $this->inSchool($w['school'], fn () => $service->guarded(fn () => DB::transaction(fn () => $service->submit(
            $w['school'], $w['entry']->id, self::MONDAY, $this->registerPayload([$w['enrollment']->id => 'absent']), $w['actor'],
        ))));
        $w['record'] = $this->inSchool($w['school'], fn () => AttendanceRecord::query()->where('attendance_session_id', $w['session']->id)->sole());

        return $w;
    }

    private function corrections(array $w): int
    {
        return $this->inSchool($w['school'], fn () => AttendanceRecordCorrection::query()->count());
    }

    #[Test]
    public function first_time_entry_needs_no_reason_and_a_correction_needs_a_closed_one(): void
    {
        $w = $this->world();
        $this->assertSame([0, null], [$this->corrections($w), $w['record']->corrected_at], 'entry is not a correction');
        $service = app(AttendanceCorrectionService::class);

        foreach (['', 'he was sick', 'other'] as $bad) {
            $this->assertThrows(fn () => $service->correct($w['school'], $w['record']->id, 'absent', 'present', $bad, $w['actor']), AttendanceCorrectionReasonException::class);
        }
        $this->assertSame(0, $this->corrections($w));

        $service->correct($w['school'], $w['record']->id, 'absent', 'late', 'late_information', $w['actor']);
        $service->correct($w['school'], $w['record']->id, 'late', 'excused', 'administrative_review', $w['actor']);

        $rows = $this->inSchool($w['school'], fn () => AttendanceRecordCorrection::query()->orderBy('corrected_at')->orderBy('id')->get());
        $this->assertSame([['absent', 'late', 'late_information'], ['late', 'excused', 'administrative_review']], $rows->map(fn ($r) => [$r->from_status, $r->to_status, $r->reason_code])->all());
        $this->assertSame([$w['actor']->id], $rows->pluck('corrected_by_user_id')->unique()->values()->all());

        $audit = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'attendance.record.corrected')->orderBy('id')->get()->pluck('metadata'));
        $this->assertSame([['absent', 'late', 'late_information'], ['late', 'excused', 'administrative_review']], $audit->map(fn ($m) => [$m['previousStatus'], $m['newStatus'], $m['reasonCode']])->all());
    }

    #[Test]
    public function the_database_refuses_a_status_change_without_its_correction_and_keeps_corrections_immutable(): void
    {
        $w = $this->world();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $refused = function (callable $statement): string {
            try {
                DB::transaction($statement);

                return '';
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        };

        $this->inSchool($w['school'], function () use ($w, $refused) {
            $this->assertStringContainsString('attendance_records_correction_evidence', $refused(fn () => DB::table('attendance_records')->where('id', $w['record']->id)->update(['status' => 'present'])));
            // Evidence for a different change does not count.
            $this->assertStringContainsString('attendance_records_correction_evidence', $refused(function () use ($w) {
                DB::table('attendance_record_corrections')->insert(['id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'attendance_record_id' => $w['record']->id,
                    'from_status' => 'absent', 'to_status' => 'late', 'reason_code' => 'late_information', 'corrected_by_user_id' => $w['actor']->id]);
                DB::table('attendance_records')->where('id', $w['record']->id)->update(['status' => 'present']);
            }));
            $this->assertStringContainsString('attendance_record_corrections_shape_check', $refused(fn () => DB::table('attendance_record_corrections')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'attendance_record_id' => $w['record']->id,
                'from_status' => 'absent', 'to_status' => 'present', 'reason_code' => 'because', 'corrected_by_user_id' => $w['actor']->id,
            ])));
        });

        app(AttendanceCorrectionService::class)->correct($w['school'], $w['record']->id, 'absent', 'present', 'entered_in_error', $w['actor']);
        $correction = $this->inSchool($w['school'], fn () => AttendanceRecordCorrection::query()->sole());
        $this->inSchool($w['school'], function () use ($correction, $refused) {
            $this->assertStringContainsString('permission denied', $refused(fn () => DB::table('attendance_record_corrections')->where('id', $correction->id)->update(['reason_code' => 'administrative_review'])));
            $this->assertStringContainsString('permission denied', $refused(fn () => DB::table('attendance_record_corrections')->where('id', $correction->id)->delete()));
        });
    }

    #[Test]
    public function the_register_page_offers_the_closed_reasons_and_a_correction_without_one_is_refused(): void
    {
        $w = $this->world();
        $this->actingAs($w['actor'])->withHeader('X-School-Id', $w['school']->id);

        $this->get("/app/attendance/{$w['session']->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('correctionReasons', AttendanceRecordCorrection::REASONS));
        $this->post("/app/attendance/records/{$w['record']->id}/correct", ['expected_status' => 'absent', 'new_status' => 'present'])->assertSessionHasErrors('reason_code');
        $this->post("/app/attendance/records/{$w['record']->id}/correct", ['expected_status' => 'absent', 'new_status' => 'present', 'reason_code' => 'administrative_review'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->corrections($w));
    }
}
