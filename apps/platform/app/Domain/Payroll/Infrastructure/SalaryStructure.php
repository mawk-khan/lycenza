<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\SalaryStructureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 9.1 (ADR 0034 "Salary structure revision model") -- each row
 * IS one immutable revision, not a mutable document with a hidden
 * version history. Frozen from the instant `status` becomes `active`
 * (database trigger `trg_salary_structures_freeze`) -- this model
 * exposes no update-after-activation helper on purpose; Checkpoint
 * 9.2's `SalaryStructureService` is the sanctioned write path.
 *
 * @property string $id
 * @property string $school_id
 * @property string $code
 * @property int $version
 * @property string $name
 * @property string $status draft|active|superseded
 */
class SalaryStructure extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $fillable = [
        'school_id',
        'code',
        'version',
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    protected static function newFactory(): SalaryStructureFactory
    {
        return SalaryStructureFactory::new();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<SalaryStructureComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class)->orderBy('display_order');
    }
}
