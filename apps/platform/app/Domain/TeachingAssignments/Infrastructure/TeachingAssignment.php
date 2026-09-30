<?php

namespace App\Domain\TeachingAssignments\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TCH.2 (ADR 0063 section 7) -- the authoritative teaching-ownership
 * fact: this Employee owns this Section + required SubjectOffering
 * teaching context from `starts_on` to `ends_on` (inclusive; NULL is
 * open-ended). It authorizes nothing by itself.
 *
 * The only write path is
 * App\Domain\TeachingAssignments\Application\TeachingAssignmentService
 * (create, end). Nothing is mass-assignable: the service force-fills every
 * column from resolved parents, and `trg_teaching_assignments_history`
 * freezes the identity at the database.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $section_id
 * @property string $subject_offering_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $created_by_user_id
 * @property Carbon|null $ended_at
 * @property string|null $ended_by_user_id
 * @property string|null $end_reason completed|reassigned|employment_ended
 * @property Carbon $created_at
 */
class TeachingAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    /** The closed end-reason catalogue (`teaching_assignments_end_shape_check`). */
    public const array END_REASONS = ['completed', 'reassigned', 'employment_ended'];

    protected $table = 'teaching_assignments';

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

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
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

    /** True when $date (School-local Y-m-d) falls inside the effective period. */
    public function isEffectiveOn(string $date): bool
    {
        return $this->starts_on->toDateString() <= $date
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $date);
    }
}
