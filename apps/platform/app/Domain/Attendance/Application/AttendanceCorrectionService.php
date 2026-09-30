<?php

namespace App\Domain\Attendance\Application;

use App\Domain\Attendance\Application\Exceptions\AttendanceCorrectionNoOpException;
use App\Domain\Attendance\Application\Exceptions\AttendanceRecordStatusChangedException;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.2: the ONE sanctioned mutator of an already-submitted
 * AttendanceRecord. Deliberately a separate, explicitly-named command
 * rather than a generic record-update endpoint -- correcting a
 * historical attendance fact is a distinct, audited act, not a field
 * edit.
 *
 * EXPECTED-STATUS COMPARE-AND-SWAP. The caller must state the status it
 * believes the record currently holds. Inside the row lock, if the
 * actual status differs, the correction is REFUSED
 * (AttendanceRecordStatusChangedException) rather than applied. This is
 * what stops a stale administrator silently overwriting a colleague's
 * correction: two admins who both read `absent` and then submit
 * different corrections will see exactly one succeed; the second is
 * told the record moved underneath them and must reload.
 *
 * HISTORICAL CORRECTIONS MUST REMAIN POSSIBLE. This service
 * deliberately does NOT re-validate the current TimetableEntry status,
 * the AcademicYear status, the StudentEnrollment status, or whether the
 * Enrollment's CURRENT interval still contains the Session's date.
 * Attendance is authoritative once submitted; a closed year, a
 * deactivated schedule entry, a withdrawn Student or a later backdated
 * SIS change must never make a genuine clerical correction impossible.
 * The only things checked are the record's own identity, tenancy and
 * expected status.
 *
 * TWO AUTHORIZATION TIERS (TCH.4, ADR 0063 section 11): Tier 1 callers
 * hold `attendance.manage` and pass no guard; a Tier 2 teacher passes an
 * AttendanceWriteGuard, run before the record lock. A teacher may correct
 * only registers whose date their TeachingAssignment covers -- today's
 * owner of a class cannot rewrite an earlier teacher's register.
 *
 * Only `status` and `corrected_at` ever change. Nothing here can touch
 * a Session column, a structural context column, or
 * `student_enrollment_id`, and there is no delete.
 */
class AttendanceCorrectionService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function correct(
        School $school,
        string $attendanceRecordId,
        string $expectedStatus,
        string $newStatus,
        User $actor,
        ?AttendanceWriteGuard $guard = null,
    ): AttendanceRecord {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $attendanceRecordId, $expectedStatus, $newStatus, $actor, $guard) {
            // TCH.4 Tier 2 only: identity and ownership of the register's
            // class ON its attendance_date, held before the record lock. A
            // Session is immutable, so the unlocked read cannot go stale.
            if ($guard !== null) {
                $session = AttendanceRecord::query()->where('id', $attendanceRecordId)->where('school_id', $school->id)->firstOrFail()->session;
                $guard->beforeCorrect($school, $session);
            }

            $record = AttendanceRecord::query()
                ->where('id', $attendanceRecordId)
                ->where('school_id', $school->id)
                ->lockForUpdate()
                ->firstOrFail();

            // CAS: evaluated only AFTER the row lock, so the value read
            // here is the committed current one, not a stale read.
            if ($record->status !== $expectedStatus) {
                throw new AttendanceRecordStatusChangedException($expectedStatus, $record->status);
            }

            // Rejected rather than silently accepted: a no-op would
            // still stamp corrected_at and write an audit row claiming a
            // change that never happened.
            if ($newStatus === $record->status) {
                throw new AttendanceCorrectionNoOpException($record->status);
            }

            $previous = $record->status;

            $record->forceFill([
                'status' => $newStatus,
                'corrected_at' => now(),
            ])->save();

            $this->audit->school($school, 'attendance.record.corrected', actor: $actor, subject: $record, metadata: [
                'recordId' => $record->id,
                'sessionId' => $record->attendance_session_id,
                'previousStatus' => $previous,
                'newStatus' => $newStatus,
            ]);

            return $record->refresh();
        }));
    }
}
