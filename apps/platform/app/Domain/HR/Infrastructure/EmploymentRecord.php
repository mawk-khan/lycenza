<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmploymentRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.4 -- one legal/organizational engagement of an Employee with
 * the School (docs/modules/HR.md canonical terminology: `Employment`).
 * An Employee may have more than one over time (rehire) -- this model
 * is never mutated in place to represent a second engagement; a new
 * row is created instead, and the prior row's history is preserved
 * untouched.
 *
 * `status` (draft|pre_joining|active|notice_period|separated|
 * terminated|retired|deceased) is the Employment's own lifecycle --
 * distinct from `Employee.record_status` (identity-record lifecycle)
 * and from `users`/`school_memberships` account status
 * (docs/modules/HR.md's state responsibility matrix). None of the
 * three are automatically synchronized.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\EmploymentService -- never create/update
 * this model directly outside a test.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $employment_type permanent|probationary|fixed_term|part_time|temporary|contract|consultant
 * @property string|null $employee_category_id Phase 8A closure correction -- nullable FK to
 *                                             `employee_categories(id, school_id)`.
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $probation_ends_on
 * @property string $status draft|pre_joining|active|notice_period|separated|terminated|retired|deceased
 */
class EmploymentRecord extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * The closed Employment status catalogue (docs/modules/HR.md state
     * responsibility matrix), database-enforced by
     * `employment_records_status_check` since TCH.1 (ADR 0063 section 5).
     */
    public const array STATUSES = ['draft', 'pre_joining', 'active', 'notice_period', 'separated', 'terminated', 'retired', 'deceased'];

    /**
     * ADR 0063 D-08: the only statuses under which an EmploymentRecord can
     * make its Employee an ActingEmployee. Every other status is ineligible.
     */
    public const array AUTHORIZATION_ELIGIBLE_STATUSES = ['active', 'notice_period'];

    protected $table = 'employment_records';

    protected $fillable = [
        'school_id',
        'employee_id',
        'employment_type',
        'employee_category_id',
        'starts_on',
        'ends_on',
        'probation_ends_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'probation_ends_on' => 'date',
        ];
    }

    protected static function newFactory(): EmploymentRecordFactory
    {
        return EmploymentRecordFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<EmployeeCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeeCategory::class, 'employee_category_id');
    }

    /** @return HasMany<EmployeeAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * "Current/ongoing employment" per docs/modules/HR.md's temporal
     * semantics -- NULL `ends_on` means open-ended, not "not yet
     * decided."
     */
    public function isOpen(): bool
    {
        return $this->ends_on === null;
    }
}
