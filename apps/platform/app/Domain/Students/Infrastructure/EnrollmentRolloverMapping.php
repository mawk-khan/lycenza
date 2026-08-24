<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EnrollmentRolloverMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 1B.7A: one plan's explicit source->target placement default --
 * either a Grade-level default (`source_section_id` null) or a
 * Section-specific override (`source_section_id` set). See this
 * model's migration for the full rationale, including exactly which
 * consistency invariants are database-structural versus deferred to a
 * future application-layer validation (Phase 1B.7B).
 *
 * Repeat/retention has no dedicated flag -- it is simply
 * `target_grade_level_id === source_grade_level_id`. Terminal Grade is
 * represented by the absence of any mapping row for a source Grade,
 * never by a row with a null target Grade.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $plan_id
 * @property string $source_grade_level_id
 * @property string|null $source_section_id
 * @property string $target_grade_level_id
 * @property string|null $target_section_id
 */
class EnrollmentRolloverMapping extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'enrollment_rollover_mappings';

    protected $fillable = [
        'school_id', 'plan_id', 'source_grade_level_id', 'source_section_id',
        'target_grade_level_id', 'target_section_id',
    ];

    protected static function newFactory(): EnrollmentRolloverMappingFactory
    {
        return EnrollmentRolloverMappingFactory::new();
    }

    public function isRepeat(): bool
    {
        return $this->target_grade_level_id === $this->source_grade_level_id;
    }

    /** @return BelongsTo<EnrollmentRolloverPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(EnrollmentRolloverPlan::class, 'plan_id');
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function sourceGradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'source_grade_level_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function sourceSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'source_section_id');
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function targetGradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'target_grade_level_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function targetSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'target_section_id');
    }
}
