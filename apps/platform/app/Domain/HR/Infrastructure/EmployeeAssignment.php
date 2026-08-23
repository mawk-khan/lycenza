<?php

namespace App\Domain\HR\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.4 -- where/how an Employee works during one EmploymentRecord
 * (docs/modules/HR.md canonical terminology: `Assignment`). Deliberately
 * has no `employee_id` column -- always reached through
 * `employment_record_id` (see the owning migration's docblock for why).
 *
 * No `status` column: docs/modules/HR.md's state responsibility matrix
 * is explicit that Assignment status is derived from `starts_on`/
 * `ends_on`, never stored -- see `isCurrent()` below.
 *
 * No `manager_assignment_id` yet -- reserved for Phase 8A.5 (Reporting
 * Hierarchy); this model intentionally does not anticipate it beyond
 * the `unique(id, school_id)` key already in place for it.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\EmployeeAssignmentService -- never create/
 * update this model directly outside a test.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property string|null $campus_id
 * @property string|null $department_id
 * @property string $position_id
 * @property bool $is_primary
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class EmployeeAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_assignments';

    protected $fillable = [
        'school_id',
        'employment_record_id',
        'campus_id',
        'department_id',
        'position_id',
        'is_primary',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function newFactory(): EmployeeAssignmentFactory
    {
        return EmployeeAssignmentFactory::new();
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * docs/modules/HR.md: "'current' = starts_on <= today <= (ends_on
     * OR infinity)" -- computed, never stored.
     */
    public function isCurrent(): bool
    {
        $today = Carbon::today();

        return $this->starts_on->lessThanOrEqualTo($today)
            && ($this->ends_on === null || $this->ends_on->greaterThanOrEqualTo($today));
    }
}
