<?php

namespace App\Domain\TeachingAssignments\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TCH-E (ADR 0063 section 45) -- the authoritative teaching-ownership fact for
 * an ELECTIVE SubjectOffering: this Employee teaches this elective Offering
 * from `starts_on` to `ends_on` (inclusive; NULL is open-ended). Offering-wide
 * (an elective has no Section cohort); it authorizes nothing by itself.
 *
 * The only write path is ElectiveTeachingAssignmentService (create, end);
 * nothing is mass-assignable, and `trg_elective_teaching_assignments_history`
 * freezes the identity and refuses a required Offering at the database.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $subject_offering_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $created_by_user_id
 * @property Carbon|null $ended_at
 * @property string|null $ended_by_user_id
 * @property string|null $end_reason completed|reassigned|employment_ended
 * @property Carbon $created_at
 */
class ElectiveTeachingAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    /** The same closed end-reason catalogue as TeachingAssignment (`elective_teaching_assignments_end_shape_check`). */
    public const array END_REASONS = TeachingAssignment::END_REASONS;

    protected $table = 'elective_teaching_assignments';

    /** @var list<string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function isEnded(): bool
    {
        return $this->ended_at !== null;
    }
}
