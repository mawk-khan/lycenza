<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CompensationAssignmentValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9.1 (ADR 0032 "Where employee-specific compensation values
 * live") -- the ONE place an individual Employee's negotiated
 * monetary number is stored, for `fixed_amount`-type
 * `SalaryStructureComponent`s only (enforced by database trigger).
 * Immutable once created (`trg_compensation_assignment_values_reject_update`
 * plus `TenantRls::makeAppendOnly()`) -- a compensation change is a
 * new `EmployeeCompensationAssignment` with its own new value rows,
 * never an edit.
 *
 * @property string $id
 * @property string $school_id
 * @property string $assignment_id
 * @property string $salary_structure_component_id
 * @property string $amount
 */
class CompensationAssignmentValue extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'assignment_id',
        'salary_structure_component_id',
        'amount',
    ];

    protected static function newFactory(): CompensationAssignmentValueFactory
    {
        return CompensationAssignmentValueFactory::new();
    }

    /** @return BelongsTo<EmployeeCompensationAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeCompensationAssignment::class, 'assignment_id');
    }

    /** @return BelongsTo<SalaryStructureComponent, $this> */
    public function structureComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryStructureComponent::class, 'salary_structure_component_id');
    }
}
