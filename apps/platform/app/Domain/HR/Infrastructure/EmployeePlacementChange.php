<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR.1A: the named, reasoned record of one placement change of an
 * employment -- which assignment it closed (`from`), which it opened (`to`),
 * on which date, of which kind, why, and who recorded it. Append-only and
 * bound by the database to the change's own employment
 * (`trg_employee_placement_changes_guard`). Written only by
 * App\Domain\HR\Application\Workforce\PlacementChangeService and
 * EmployeeAssignmentService::setPrimaryRecord().
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property string $change_kind
 * @property string|null $from_assignment_id
 * @property string $to_assignment_id
 * @property Carbon $effective_on
 * @property string $reason_code
 * @property string $recorded_by_user_id
 * @property Carbon $created_at
 */
class EmployeePlacementChange extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'employee_placement_changes';

    protected $fillable = [
        'school_id', 'employment_record_id', 'change_kind', 'from_assignment_id', 'to_assignment_id',
        'effective_on', 'reason_code', 'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmployeeAssignment, $this> */
    public function fromAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'from_assignment_id');
    }

    /** @return BelongsTo<EmployeeAssignment, $this> */
    public function toAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'to_assignment_id');
    }
}
