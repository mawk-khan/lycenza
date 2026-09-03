<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2 API surface: submission (with Idempotency-Key), the
 * register reads, the two helpers, correction, capability allow/deny on
 * every route, and Sensitive-tier response minimization.
 */
class AttendanceApiTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    private function as(array $w, ?object $actor = null): static
    {
        return $this->actingAs($actor ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function submitPayload(array $w, array $records, ?string $date = null): array
    {
        return [
            'timetable_entry_id' => $w['entry']->id,
            'attendance_date' => $date ?? self::MONDAY,
            'records' => $records,
        ];
    }

    private function submitViaApi(array $w, array $records, string $key = 'attendance-key-0001'): TestResponse
    {
        return $this->as($w)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($this->base($w).'/attendance-sessions', $this->submitPayload($w, $records));
    }

    // --- submission -------------------------------------------------

    #[Test]
    public function a_complete_register_can_be_submitted_and_read_back(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $b = $this->enrollStudent($w['section'], '2', '2026-06-01');

        $response = $this->submitViaApi($w, $this->registerPayload([$a->id => 'present', $b->id => 'late']));
        $response->assertCreated();

        $data = $response->json('data');
        $this->assertSame($w['section']->id, $data['sectionId']);
        $this->assertSame('09:00:00', $data['periodStartTime']);
        $this->assertSame(1, $data['dayOfWeek']);
        $this->assertCount(2, $data['records']);

        $show = $this->as($w)->getJson($this->base($w)."/attendance-sessions/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));

        $index = $this->as($w)->getJson($this->base($w).'/attendance-sessions');
        $index->assertOk();
        $this->assertSame(1, $index->json('meta.total'));
    }

    #[Test]
    public function the_register_response_exposes_no_student_or_employee_pii(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $data = $this->submitViaApi($w, $this->registerPayload([$a->id => 'present']))->json('data');
        $encoded = json_encode($data);

        // Student projection is exactly id + roll number + display name.
        $record = $data['records'][0];
        $this->assertSame(
            ['id', 'studentEnrollmentId', 'studentId', 'rollNumber', 'fullName', 'status', 'correctedAt'],
            array_keys($record),
        );

        foreach (['dateOfBirth', 'date_of_birth', 'guardian', 'phone', 'email', 'address', 'studentNumber'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded,
                "Sensitive-tier response must not expose {$forbidden}.");
        }

        // The record's structural context columns are never serialized.
        foreach (['academicYearId', 'campusId', 'gradeLevelId', 'sectionId'] as $structural) {
            $this->assertArrayNotHasKey($structural, $record);
        }

        // Teacher: id + fullName only.
        $this->assertArrayHasKey('teacherId', $data);
        $this->assertArrayHasKey('teacherName', $data);
        foreach (['workEmail', 'work_email', 'workPhone', 'employeeNumber'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    #[Test]
    public function submission_requires_an_idempotency_key(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->as($w)
            ->postJson($this->base($w).'/attendance-sessions', $this->submitPayload($w, $this->registerPayload([$a->id => 'present'])))
            ->assertStatus(400);
    }

    #[Test]
    public function an_identical_retry_replays_the_stored_response_without_re_running_the_mutation(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $records = $this->registerPayload([$a->id => 'present']);

        $first = $this->submitViaApi($w, $records, 'attendance-retry-key');
        $first->assertCreated();

        $second = $this->submitViaApi($w, $records, 'attendance-retry-key');
        $second->assertCreated();
        $second->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // The business mutation ran exactly once.
        $this->assertSame(1, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => AttendanceRecord::query()->count()));
    }

    #[Test]
    public function a_second_submission_with_a_different_key_is_a_conflict_not_a_silent_success(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->submitViaApi($w, $this->registerPayload([$a->id => 'present']), 'attendance-key-aaaa')->assertCreated();

        $second = $this->submitViaApi($w, $this->registerPayload([$a->id => 'absent']), 'attendance-key-bbbb');
        $second->assertStatus(409);
        $this->assertSame('ATTENDANCE_SESSION_ALREADY_SUBMITTED', $second->json('error.code') ?? $second->json('code'));

        $this->assertSame('present', $this->inSchool($w['school'], fn () => AttendanceRecord::query()->firstOrFail()->status));
    }

    #[Test]
    public function an_invalid_status_is_rejected_by_validation(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->submitViaApi($w, [['student_enrollment_id' => $a->id, 'status' => 'sick']])
            ->assertStatus(422);
    }

    #[Test]
    public function an_empty_records_array_is_rejected_by_validation(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->submitViaApi($w, [])->assertStatus(422);
    }

    // --- helpers ----------------------------------------------------

    #[Test]
    public function the_scheduled_classes_helper_returns_only_active_current_entries_for_the_weekday(): void
    {
        $w = $this->attendanceWorld();

        $response = $this->as($w)->getJson($this->base($w).'/attendance-sessions/scheduled-classes?attendance_date='.self::MONDAY);
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($w['entry']->id, $response->json('data.0.timetableEntryId'));
        $this->assertFalse($response->json('data.0.alreadySubmitted'));

        // A Tuesday returns nothing for a Monday-scheduled entry.
        $this->as($w)->getJson($this->base($w).'/attendance-sessions/scheduled-classes?attendance_date=2026-08-25')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_roster_preview_helper_uses_the_shared_as_of_date_predicate(): void
    {
        $w = $this->attendanceWorld();
        $early = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->enrollStudent($w['section'], '2', '2026-10-01'); // starts after the date

        $response = $this->as($w)->getJson($this->base($w)
            ."/attendance-sessions/roster-preview?timetable_entry_id={$w['entry']->id}&attendance_date=".self::MONDAY);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($early->id, $response->json('data.0.studentEnrollmentId'));
        $this->assertFalse($response->json('meta.authoritative'));

        // Minimal projection only.
        $this->assertSame(
            ['studentEnrollmentId', 'studentId', 'rollNumber', 'fullName'],
            array_keys($response->json('data.0')),
        );
    }

    // --- correction -------------------------------------------------

    #[Test]
    public function a_record_can_be_corrected_with_expected_status_semantics(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $data = $this->submitViaApi($w, $this->registerPayload([$a->id => 'absent']))->json('data');
        $recordId = $data['records'][0]['id'];

        $ok = $this->as($w)->postJson($this->base($w)."/attendance-records/{$recordId}/correct", [
            'expected_status' => 'absent', 'new_status' => 'present',
        ]);
        $ok->assertOk();
        $this->assertSame('present', $ok->json('data.status'));
        $this->assertNotNull($ok->json('data.correctedAt'));

        // A stale expected_status now conflicts.
        $this->as($w)->postJson($this->base($w)."/attendance-records/{$recordId}/correct", [
            'expected_status' => 'absent', 'new_status' => 'excused',
        ])->assertStatus(409);

        // A no-op is a validation-level rejection.
        $this->as($w)->postJson($this->base($w)."/attendance-records/{$recordId}/correct", [
            'expected_status' => 'present', 'new_status' => 'present',
        ])->assertStatus(422);
    }

    // --- authorization ---------------------------------------------

    #[Test]
    public function every_attendance_route_denies_an_actor_without_the_capability(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $data = $this->submitViaApi($w, $this->registerPayload([$a->id => 'absent']))->json('data');

        // Holds neither attendance capability.
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->as($w, $outsider)->getJson($this->base($w).'/attendance-sessions')->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/attendance-sessions/{$data['id']}")->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w).'/attendance-sessions/scheduled-classes?attendance_date='.self::MONDAY)->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/attendance-sessions/roster-preview?timetable_entry_id={$w['entry']->id}&attendance_date=".self::MONDAY)->assertForbidden();
        $this->as($w, $outsider)->withHeader('Idempotency-Key', 'attendance-deny-key')
            ->postJson($this->base($w).'/attendance-sessions', $this->submitPayload($w, []))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->base($w)."/attendance-records/{$data['records'][0]['id']}/correct", [
            'expected_status' => 'absent', 'new_status' => 'present',
        ])->assertForbidden();
    }

    #[Test]
    public function a_view_only_actor_can_read_but_never_write(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $data = $this->submitViaApi($w, $this->registerPayload([$a->id => 'absent']))->json('data');

        $viewer = $this->createUserWithCapabilities($w['school'], ['attendance.view']);

        $this->as($w, $viewer)->getJson($this->base($w).'/attendance-sessions')->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/attendance-sessions/{$data['id']}")->assertOk();

        $this->as($w, $viewer)->withHeader('Idempotency-Key', 'attendance-viewer-key')
            ->postJson($this->base($w).'/attendance-sessions', $this->submitPayload($w, []))->assertForbidden();
        $this->as($w, $viewer)->postJson($this->base($w)."/attendance-records/{$data['records'][0]['id']}/correct", [
            'expected_status' => 'absent', 'new_status' => 'present',
        ])->assertForbidden();

        // The helpers require `.manage` -- they exist to prepare a write.
        $this->as($w, $viewer)->getJson($this->base($w).'/attendance-sessions/scheduled-classes?attendance_date='.self::MONDAY)->assertForbidden();
    }

    #[Test]
    public function another_schools_register_is_not_reachable(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $data = $this->submitViaApi($w, $this->registerPayload([$a->id => 'absent']))->json('data');

        $other = $this->attendanceWorld();

        // School B's actor, School B's URL, School A's Session id.
        $this->actingAs($other['actor'])->withHeader('X-School-Id', $other['school']->id)
            ->getJson("/api/v1/schools/{$other['school']->id}/attendance-sessions/{$data['id']}")
            ->assertNotFound();

        // School B's actor cannot act inside School A's URL either. The
        // membership middleware answers 404 rather than 403 here --
        // deliberately not leaking whether that School exists -- so
        // accept either refusal, and assert no register content leaks
        // in the body.
        $crossSchool = $this->actingAs($other['actor'])->withHeader('X-School-Id', $other['school']->id)
            ->getJson($this->base($w)."/attendance-sessions/{$data['id']}");

        $this->assertContains($crossSchool->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString($w['section']->id, $crossSchool->getContent());
    }

    #[Test]
    public function a_correction_cannot_reach_another_schools_record(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $recordId = $this->submitViaApi($w, $this->registerPayload([$a->id => 'absent']))->json('data.records.0.id');

        $other = $this->attendanceWorld();

        $this->actingAs($other['actor'])->withHeader('X-School-Id', $other['school']->id)
            ->postJson("/api/v1/schools/{$other['school']->id}/attendance-records/{$recordId}/correct", [
                'expected_status' => 'absent', 'new_status' => 'present',
            ])->assertNotFound();

        $this->assertSame('absent', $this->inSchool($w['school'], fn () => AttendanceRecord::query()->findOrFail($recordId)->status));
    }

    #[Test]
    public function the_ambiguous_history_guard_surfaces_as_a_typed_conflict(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->inSchool($w['school'], fn () => StudentEnrollment::query()->create([
            'school_id' => $w['school']->id,
            'student_id' => $a->student_id,
            'academic_year_id' => $w['year']->id,
            'campus_id' => $w['campus']->id,
            'grade_level_id' => $w['grade']->id,
            'section_id' => $w['section']->id,
            'roll_number' => '99',
            'status' => 'cancelled',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-01-01',
        ]));

        $this->submitViaApi($w, $this->registerPayload([$a->id => 'present']))->assertStatus(409);
        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }

    #[Test]
    public function submitting_from_another_schools_timetable_entry_is_not_possible(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-06-01');
        $other = $this->attendanceWorld();

        $this->as($w)->withHeader('Idempotency-Key', 'attendance-cross-key')
            ->postJson($this->base($w).'/attendance-sessions', [
                'timetable_entry_id' => $other['entry']->id,
                'attendance_date' => self::MONDAY,
                'records' => [],
            ])->assertStatus(422);

        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }
}
