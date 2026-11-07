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
 * `ends_on`, never stored -- operationally current is
 * App\Domain\HR\Application\CurrentEmployeeAssignments (ORG.7E-0).
 *
 * HR.3A: an assignment carries no reporting line. Reporting lines join
 * EMPLOYMENTS over dates (App\Domain\HR\Infrastructure\ReportingRelationship);
 * the 8A.5 `manager_assignment_id` pointer was removed.
 *
 * The only sanctioned write path for every other column is
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

    /** HR.R1-C13 (GOV-6): nothing by mass assignment. Every column is stated by its writer. */
    protected $fillable = [];

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

    /**
     * HR.R1-C2: the empty interval `ends_on = starts_on - 1`, which only
     * EmploymentService::end() records, for a placement planned to start
     * after the employment's last day. It covers no day in any reader.
     */
    public function isNeverEffective(): bool
    {
        return $this->ends_on !== null && $this->ends_on->lt($this->starts_on);
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
}
