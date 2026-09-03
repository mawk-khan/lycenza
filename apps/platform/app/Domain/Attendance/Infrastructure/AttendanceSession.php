<?php

namespace App\Domain\Attendance\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\Campus;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\AttendanceSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned immutable header of one SUBMITTED class register
 * (Phase 0H.2). Persistence + relationships only --
 * `App\Domain\Attendance\Application\AttendanceSubmissionService` is
 * the sole write path.
 *
 * IMMUTABLE AFTER INSERT. There is no status column, no update
 * endpoint, no replace, and no delete: a Session either exists (the
 * register was submitted) or it does not. Corrections happen on
 * individual `attendance_records` rows.
 *
 * `academic_year_id`/`campus_id`/`grade_level_id`/`section_id`/
 * `subject_offering_id`/`teacher_id`/`period_id`/`period_start_time`/
 * `period_end_time` are an immutable SNAPSHOT, server-derived from the
 * locked TimetableEntry (and its Period) at submission. They -- never
 * the current TimetableEntry -- are this register's historical class
 * identity. See the `create_attendance_sessions_table` migration and
 * docs/modules/ATTENDANCE.md.
 *
 * `timetable_entry_id` is PROVENANCE ONLY: the TimetableEntry from
 * which this Session was instantiated at submission time. Phase 0H.1's
 * TimetableEntry is fully mutable afterwards, so its CURRENT
 * Section/SubjectOffering/teacher/Period/day-of-week/context values
 * carry no historical authority here. `timetableEntry()` below exists
 * for provenance lookups ONLY -- never call it to hydrate a historical
 * register's class context.
 *
 * @property string $id
 * @property string $school_id
 * @property string $timetable_entry_id provenance only -- see docblock
 * @property Carbon $attendance_date
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $section_id
 * @property string $subject_offering_id
 * @property string $teacher_id
 * @property string $period_id
 * @property string $period_start_time immutable historical wall-clock start
 * @property string $period_end_time immutable historical wall-clock end
 * @property string $submitted_by_user_id
 * @property Carbon $submitted_at
 */
class AttendanceSession extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'attendance_sessions';

    protected $fillable = [
        'school_id', 'timetable_entry_id', 'attendance_date',
        'academic_year_id', 'campus_id', 'grade_level_id', 'section_id',
        'subject_offering_id', 'teacher_id', 'period_id',
        'period_start_time', 'period_end_time',
        'submitted_by_user_id', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AttendanceSessionFactory
    {
        return AttendanceSessionFactory::new();
    }

    /**
     * ISO-8601 weekday (1 = Monday .. 7 = Sunday) DERIVED from
     * `attendance_date`. Deliberately not a stored column and never
     * read from `timetable_entries.day_of_week` -- that column is
     * mutable and would silently re-date a historical register.
     */
    public function dayOfWeek(): int
    {
        return (int) $this->attendance_date->isoWeekday();
    }

    /** @return HasMany<AttendanceRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'attendance_session_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'teacher_id');
    }

    /**
     * Resolves the CURRENT Period row -- used ONLY for its present-day
     * `code`/`name` labels. The register's historical wall-clock time
     * is `period_start_time`/`period_end_time` on this row; never read
     * `start_time`/`end_time` from here for a historical register.
     *
     * @return BelongsTo<TimetablePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(TimetablePeriod::class, 'period_id');
    }

    /**
     * PROVENANCE ONLY -- see this class's docblock. Never used to
     * hydrate historical class context.
     *
     * @return BelongsTo<TimetableEntry, $this>
     */
    public function timetableEntry(): BelongsTo
    {
        return $this->belongsTo(TimetableEntry::class, 'timetable_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
