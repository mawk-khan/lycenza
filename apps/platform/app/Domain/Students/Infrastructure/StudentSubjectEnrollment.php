<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
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
 * `student_enrollment_id` and `elective_group_id` are Phase 1F.1
 * additions (schema/model only -- StudentSubjectEnrollmentService does
 * not yet populate them, Phase 1F.2). Both nullable -- see
 * docs/students/PHASE-1F-0-ELECTIVE-MUTUAL-EXCLUSIVITY-ARCHITECTURE.md
 * §10/§11A/§11B/§18B for the exact invariants a PostgreSQL trigger and
 * two composite FKs enforce on these columns.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $student_id
 * @property string|null $student_enrollment_id
 * @property string $subject_offering_id
 * @property string|null $elective_group_id
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
        'school_id', 'student_id', 'student_enrollment_id', 'subject_offering_id', 'elective_group_id',
        'academic_year_id', 'status', 'starts_on', 'ends_on',
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

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    /** @return BelongsTo<ElectiveGroup, $this> */
    public function electiveGroup(): BelongsTo
    {
        return $this->belongsTo(ElectiveGroup::class);
    }
}
