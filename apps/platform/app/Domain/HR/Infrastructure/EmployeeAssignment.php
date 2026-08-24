<?php

namespace App\Domain\HR\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * `manager_assignment_id` (Phase 8A.5, docs/modules/HR.md "Reporting
 * hierarchy strategy") -- nullable, self-referencing, same-School
 * composite FK to another EmployeeAssignment. The only sanctioned
 * write path for this column is
 * App\Domain\HR\Application\ReportingHierarchyService::setManager() --
 * never assign it directly outside a test (self-report and reporting-
 * cycle safety both live there, not in this model).
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
 * @property string|null $manager_assignment_id
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
        'manager_assignment_id',
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

    /** @return BelongsTo<EmployeeAssignment, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_assignment_id');
    }

    /**
     * All Assignments reporting to this one -- both historical and
     * current. Filter to `->whereNull('ends_on')` for "current direct
     * reports" (docs/modules/HR.md's own "current" definition applies
     * to the reporting Assignment's own dates, same as `isCurrent()`).
     *
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_assignment_id');
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
