<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EnrollmentRolloverSubjectMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 1G.1: one plan's explicit source SubjectOffering -> target
 * SubjectOffering elective carry-forward configuration. See this
 * model's migration for the full three-state (unconfigured/omit/mapped)
 * rationale -- `target_subject_offering_id === null` on an EXISTING row
 * means explicit omit, never "unconfigured" (that state is the absence
 * of any row at all).
 *
 * No business-heavy model methods -- every mutation goes through
 * App\Domain\Students\Application\EnrollmentRolloverPlanService.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $plan_id
 * @property string $source_subject_offering_id
 * @property string|null $target_subject_offering_id
 */
class EnrollmentRolloverSubjectMapping extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'enrollment_rollover_subject_mappings';

    protected $fillable = [
        'school_id', 'plan_id', 'source_subject_offering_id', 'target_subject_offering_id',
    ];

    protected static function newFactory(): EnrollmentRolloverSubjectMappingFactory
    {
        return EnrollmentRolloverSubjectMappingFactory::new();
    }

    public function isExplicitOmit(): bool
    {
        return $this->target_subject_offering_id === null;
    }

    /** @return BelongsTo<EnrollmentRolloverPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(EnrollmentRolloverPlan::class, 'plan_id');
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function sourceSubjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'source_subject_offering_id');
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function targetSubjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'target_subject_offering_id');
    }
}
