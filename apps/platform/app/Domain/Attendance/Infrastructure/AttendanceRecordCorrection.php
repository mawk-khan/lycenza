<?php

namespace App\Domain\Attendance\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ORG.8E (ADR 0076 §63, 8E-D2): one correction of an already-recorded
 * Student Attendance fact -- from and to status, the closed reason and who
 * corrected it. Append-only; `corrected_at` is the database's transaction
 * timestamp (never set by the application), which binds the record's status
 * change to it (`attendance_records_correction_evidence`). Written only by
 * AttendanceCorrectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $attendance_record_id
 * @property string $from_status
 * @property string $to_status
 * @property string $reason_code
 * @property string $corrected_by_user_id
 * @property Carbon $corrected_at
 */
class AttendanceRecordCorrection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    /**
     * Closed and code-owned; mirrored by `attendance_record_corrections_shape_check`. They say why the RECORD
     * changed, never why a Student was absent (no health, evidence or note: AttendanceArchitectureGuardTest);
     * the Staff Attendance correction set (ADR 0065 §24.8). No free text.
     */
    public const array REASONS = ['entered_in_error', 'late_information', 'administrative_review'];

    public $timestamps = false;

    protected $table = 'attendance_record_corrections';

    protected $fillable = ['school_id', 'attendance_record_id', 'from_status', 'to_status', 'reason_code', 'corrected_by_user_id'];

    protected function casts(): array
    {
        return ['corrected_at' => 'datetime'];
    }
}
