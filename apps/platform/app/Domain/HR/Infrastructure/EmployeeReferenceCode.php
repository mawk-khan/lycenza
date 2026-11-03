<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR.2A (D1-03): one external reference of an Employee -- a Staff Reference
 * Code, an ERP code or a legacy code (closed EmployeeReferenceKind). It is
 * never the Employee's identity (`employees.id`) nor the Staff Code
 * (`employee_number`). School-unique per kind and never reused (retired rows
 * keep their value); at most one live code per kind and Employee. Immutable:
 * a change is retire + a new code (`trg_employee_reference_codes_guard`).
 * Written only by App\Domain\HR\Application\Profile\EmployeeReferenceCodeService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $kind
 * @property string $value
 * @property string $recorded_by_user_id
 * @property Carbon|null $retired_at
 * @property string|null $retired_by_user_id
 * @property string|null $retire_reason
 * @property Carbon $created_at
 */
class EmployeeReferenceCode extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'employee_reference_codes';

    /** Nothing is mass assignable: the service writes every column explicitly. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
