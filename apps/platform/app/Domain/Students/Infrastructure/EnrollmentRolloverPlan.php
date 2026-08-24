<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EnrollmentRolloverPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 1B.7A: the durable header of a reviewed, bulk cross-Academic-
 * Year Enrollment rollover -- see docs/modules/STUDENT-ENROLLMENT.md
 * ("Academic-Year Rollover & Promotion — Architecture Decision (Phase
 * 1B.7)") for the accepted design this schema implements, and this
 * migration's own docblock for every constraint's rationale.
 *
 * Deliberately has NO dry-run/execution methods -- this checkpoint is
 * schema/domain foundation only. `EnrollmentRolloverPlanService::createDraft()`
 * is the sole sanctioned write path for THIS model in this checkpoint;
 * mapping/item creation has no service yet (Phase 1B.7B).
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $source_academic_year_id
 * @property string $target_academic_year_id
 * @property string $status draft|validated|executing|completed|completed_with_errors|cancelled
 * @property int $configuration_version
 * @property int|null $validated_configuration_version
 * @property string|null $created_by_user_id
 * @property Carbon|null $validated_at
 * @property Carbon|null $execution_started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 */
class EnrollmentRolloverPlan extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'enrollment_rollover_plans';

    protected $fillable = [
        'school_id', 'source_academic_year_id', 'target_academic_year_id', 'status',
        'configuration_version', 'validated_configuration_version', 'created_by_user_id',
        'validated_at', 'execution_started_at', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'execution_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EnrollmentRolloverPlanFactory
    {
        return EnrollmentRolloverPlanFactory::new();
    }

    /**
     * Whether the plan's current configuration matches what was last
     * validated -- the explicit staleness signal a future dry-run/
     * execution flow relies on (never inferred from timestamps alone).
     */
    public function isValidatedForCurrentConfiguration(): bool
    {
        return $this->validated_configuration_version === $this->configuration_version;
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function sourceAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'source_academic_year_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function targetAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'target_academic_year_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<EnrollmentRolloverMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(EnrollmentRolloverMapping::class, 'plan_id');
    }

    /** @return HasMany<EnrollmentRolloverItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(EnrollmentRolloverItem::class, 'plan_id');
    }
}
