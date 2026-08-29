<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\SalaryStructureComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9.1 (ADR 0032 "Compensation model") -- the calculation SHAPE
 * for one `SalaryStructure` revision: `fixed_amount` or
 * `percentage_of_base` (`rate`, a decimal FRACTION 0-1). Frozen the
 * instant the parent structure leaves `draft`
 * (`trg_salary_structure_components_freeze`); a `percentage_of_base`
 * row's `base_component_id` must reference an earlier-ordered
 * component in the same structure
 * (`trg_salary_structure_components_base_order`).
 *
 * @property string $id
 * @property string $school_id
 * @property string $salary_structure_id
 * @property string $salary_component_id
 * @property string $calculation_type fixed_amount|percentage_of_base
 * @property string|null $base_component_id
 * @property string|null $rate
 * @property int $display_order
 */
class SalaryStructureComponent extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'salary_structure_id',
        'salary_component_id',
        'calculation_type',
        'base_component_id',
        'rate',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    protected static function newFactory(): SalaryStructureComponentFactory
    {
        return SalaryStructureComponentFactory::new();
    }

    public function isFixedAmount(): bool
    {
        return $this->calculation_type === 'fixed_amount';
    }

    public function isPercentageOfBase(): bool
    {
        return $this->calculation_type === 'percentage_of_base';
    }

    /** @return BelongsTo<SalaryStructure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    /** @return BelongsTo<SalaryComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /** @return BelongsTo<SalaryStructureComponent, $this> */
    public function baseComponent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_component_id');
    }
}
