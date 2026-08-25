<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\StudentSubjectEnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 1C.1 -- a Student's explicit membership in one elective/optional
 * SubjectOffering (`subject_offerings.is_required = false`). Never
 * created for a REQUIRED offering -- see the creating migration's
 * docblock and App\Domain\Students\Application\StudentSubjectEnrollmentService::enroll().
 *
 * `academic_year_id` is a denormalized copy of what `subject_offering_id`
 * already implies -- kept consistent by StudentSubjectEnrollmentService,
 * never a database constraint (identical rationale to StudentEnrollment's
 * own denormalization).
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $student_id
 * @property string $subject_offering_id
 * @property string $academic_year_id
 * @property string $status active|withdrawn|cancelled|transferred
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class StudentSubjectEnrollment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'student_subject_enrollments';

    protected $fillable = [
        'school_id', 'student_id', 'subject_offering_id', 'academic_year_id',
        'status', 'starts_on', 'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function newFactory(): StudentSubjectEnrollmentFactory
    {
        return StudentSubjectEnrollmentFactory::new();
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

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
