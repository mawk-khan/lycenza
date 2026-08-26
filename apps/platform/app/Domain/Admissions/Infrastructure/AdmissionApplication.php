<?php

namespace App\Domain\Admissions\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\AdmissionApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One specific application: one Applicant, applying to one School, for
 * one academic context (AcademicYear + Campus + GradeLevel), with its
 * own status/decision/conversion state (docs/modules/ADMISSIONS.md
 * §3/§5). No `section_id` -- Section is a conversion-time-only
 * decision (`ADMISSIONS.md` §5), never represented here.
 *
 * `status`'s allowed values (`draft`, `submitted`, `accepted`,
 * `rejected`, `withdrawn`, `converted`) are an application-level
 * concern, not a database CHECK (`ADMISSIONS.md` §6A) -- this model
 * deliberately does NOT wrap `status` in a PHP backed enum here,
 * matching `Student::$status`'s identical plain-string precedent; a
 * future lifecycle-service checkpoint (1D.2) is where transition
 * validation actually lives, not this schema-foundation model.
 *
 * `converted_student_id`/`converted_student_enrollment_id`/
 * `converted_at` are PURE PROVENANCE in this checkpoint -- Phase 1D.1
 * ships only the schema; no service sets them, no observer hooks
 * Student/SIS creation, and no conversion behavior exists yet
 * (`ADMISSIONS.md` §7/§8, a future 1D.3 checkpoint).
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $applicant_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $status
 * @property string|null $decision_note
 * @property string|null $converted_student_id
 * @property string|null $converted_student_enrollment_id
 * @property Carbon|null $converted_at
 */
class AdmissionApplication extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'admission_applications';

    protected $fillable = [
        'school_id', 'applicant_id', 'academic_year_id', 'campus_id', 'grade_level_id',
        'status', 'decision_note', 'converted_student_id', 'converted_student_enrollment_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime'];
    }

    protected static function newFactory(): AdmissionApplicationFactory
    {
        return AdmissionApplicationFactory::new();
    }

    /** @return BelongsTo<Applicant, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
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

    /**
     * Conversion provenance only -- see class docblock. Nullable: most
     * applications never reach `converted`.
     *
     * @return BelongsTo<Student, $this>
     */
    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }

    /**
     * Conversion provenance only -- see class docblock.
     *
     * @return BelongsTo<StudentEnrollment, $this>
     */
    public function convertedStudentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'converted_student_enrollment_id');
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }

    /**
     * Phase 1D.2 status helpers -- plain string-status checks, matching
     * `isConverted()` above and `Campus::isActive()`/`GradeLevel::isActive()`'s
     * identical precedent (no PHP backed enum; `status`'s allowed
     * values remain an application-level concern, `ADMISSIONS.md` §6A).
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isWithdrawn(): bool
    {
        return $this->status === 'withdrawn';
    }
}
