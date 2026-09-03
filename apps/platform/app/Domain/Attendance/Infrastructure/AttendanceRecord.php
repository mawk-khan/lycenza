<?php

namespace App\Domain\Attendance\Infrastructure;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One StudentEnrollment's attendance status inside one submitted
 * register (Phase 0H.2). Created only by
 * `App\Domain\Attendance\Application\AttendanceSubmissionService` (as a
 * complete set) and mutated only by
 * `App\Domain\Attendance\Application\AttendanceCorrectionService`
 * (expected-status compare-and-swap). Never hard-deleted.
 *
 * `student_enrollment_id` is PROVENANCE: the placement that qualified
 * this Student for this register AT SUBMISSION TIME. A later
 * transfer/withdrawal/completion/cancellation/rollover -- including a
 * BACKDATED one -- never rewrites Attendance, so this must not be read
 * as a claim that the Enrollment's CURRENT interval still contains the
 * Session's `attendance_date`.
 *
 * `academic_year_id`/`campus_id`/`grade_level_id`/`section_id` are
 * STRUCTURAL ONLY -- they exist so the two composite FKs on this table
 * can pin the Session and the Enrollment to the same context (see the
 * migration's docblock). They are server-derived from the
 * just-created Session, never client input, never updated, and never
 * serialized into an API response. They are deliberately absent from
 * `$fillable` so no mass assignment can reach them; the submission
 * service writes them with an explicit column list.
 *
 * No `student_id`: Student identity is derived through the Enrollment.
 * No note/reason/remark/minutes-late/evidence field of any kind --
 * `excused` records the generic status and never why.
 *
 * @property string $id
 * @property string $school_id
 * @property string $attendance_session_id
 * @property string $student_enrollment_id provenance -- see docblock
 * @property string $academic_year_id structural only
 * @property string $campus_id structural only
 * @property string $grade_level_id structural only
 * @property string $section_id structural only
 * @property string $status present|absent|late|excused
 * @property Carbon|null $corrected_at
 */
class AttendanceRecord extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * The complete, closed status vocabulary. Mirrored by the
     * database's own `attendance_records_status_check` CHECK
     * constraint, which is the authoritative guarantee.
     */
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    protected $table = 'attendance_records';

    /**
     * Deliberately excludes every structural context column and
     * `student_enrollment_id` -- see this class's docblock.
     */
    protected $fillable = ['status'];

    protected function casts(): array
    {
        return ['corrected_at' => 'datetime'];
    }

    protected static function newFactory(): AttendanceRecordFactory
    {
        return AttendanceRecordFactory::new();
    }

    /** @return BelongsTo<AttendanceSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }
}
