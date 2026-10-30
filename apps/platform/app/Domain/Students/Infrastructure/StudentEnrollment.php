<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\StudentEnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Student's academic placement for one specific period (Phase 1B.1)
 * -- deliberately separate from Student identity (Phase 1A), which
 * survives promotion/section movement/campus movement/year rollover/
 * withdrawal/transfer/re-enrollment untouched. A Student accumulates
 * many StudentEnrollment rows over their School lifetime; a row is
 * never mutated to represent a later year (docs/modules/STUDENT-ENROLLMENT.md
 * "Historical record principle").
 *
 * `academic_year_id`/`campus_id`/`grade_level_id` are denormalized
 * copies of what `section_id` already implies -- see the migration's
 * docblock for why, and why keeping them consistent is this model's
 * write-service's responsibility (App\Domain\Students\Application\StudentEnrollmentService,
 * Phase 1B.4), not a database constraint.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $section_id
 * @property string $roll_number
 * @property string $status active|completed|withdrawn|transferred|cancelled
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class StudentEnrollment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /** Mirrors `student_enrollments_status_check` (ORG.8G; StudentLifecycleStructureTest pins the two). */
    public const STATUSES = ['active', 'completed', 'withdrawn', 'transferred', 'cancelled'];

    protected $table = 'student_enrollments';

    /**
     * SIS.HARDEN-1 (SIS.EXP-R1 finding R-3): ownership and lifecycle are never
     * mass assignable -- `school_id` (BelongsToSchool fills it from the trusted
     * context), `status` and `ends_on` (only StudentEnrollmentService's
     * transitions write them). The one creation path sets them explicitly
     * (forceCreate). Pinned by StudentEnrollmentMassAssignmentTest.
     */
    public const PROTECTED_FIELDS = ['school_id', 'status', 'ends_on'];

    protected $fillable = [
        'student_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id',
        'roll_number', 'starts_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function newFactory(): StudentEnrollmentFactory
    {
        return StudentEnrollmentFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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
        return $this->belongsTo(Section::class);
    }
}
