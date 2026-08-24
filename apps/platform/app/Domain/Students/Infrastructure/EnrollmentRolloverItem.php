<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EnrollmentRolloverItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 1B.7A: the durable per-Student unit of a rollover plan. See
 * this model's migration for the full column-family rationale
 * (configuration / validation-staleness / execution, populated by
 * three different future checkpoints) and exactly which cross-column
 * consistency invariants are database-structural (the double
 * `source_enrollment_id` composite FK) versus deferred.
 *
 * `target_enrollment_id` is the idempotency anchor the accepted
 * architecture calls for -- once set, a retried/duplicate execution
 * attempt for this item is recognized as already-done, never
 * re-executed. Nothing in this checkpoint sets it.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $plan_id
 * @property string $student_id
 * @property string $source_enrollment_id
 * @property string|null $mapping_id
 * @property string $decision undecided|promote|repeat|exclude|manual_review
 * @property string|null $target_section_id
 * @property string|null $roll_number_strategy explicit|preserve_source
 * @property string|null $target_roll_number
 * @property string|null $validation_result
 * @property string|null $validation_reason
 * @property string|null $source_enrollment_status_snapshot
 * @property Carbon|null $source_enrollment_updated_at_snapshot
 * @property string|null $target_section_status_snapshot
 * @property Carbon|null $target_section_updated_at_snapshot
 * @property string|null $execution_status pending|succeeded|failed|skipped
 * @property string|null $target_enrollment_id
 * @property Carbon|null $executed_at
 */
class EnrollmentRolloverItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'enrollment_rollover_items';

    protected $fillable = [
        'school_id', 'plan_id', 'student_id', 'source_enrollment_id', 'mapping_id',
        'decision', 'target_section_id', 'roll_number_strategy', 'target_roll_number',
        'validation_result', 'validation_reason',
        'source_enrollment_status_snapshot', 'source_enrollment_updated_at_snapshot',
        'target_section_status_snapshot', 'target_section_updated_at_snapshot',
        'execution_status', 'target_enrollment_id', 'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_enrollment_updated_at_snapshot' => 'datetime',
            'target_section_updated_at_snapshot' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EnrollmentRolloverItemFactory
    {
        return EnrollmentRolloverItemFactory::new();
    }

    public function isRepeat(): bool
    {
        return $this->decision === 'repeat';
    }

    public function hasExecuted(): bool
    {
        return $this->target_enrollment_id !== null;
    }

    /** @return BelongsTo<EnrollmentRolloverPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(EnrollmentRolloverPlan::class, 'plan_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function sourceEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'source_enrollment_id');
    }

    /** @return BelongsTo<EnrollmentRolloverMapping, $this> */
    public function mapping(): BelongsTo
    {
        return $this->belongsTo(EnrollmentRolloverMapping::class, 'mapping_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function targetSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'target_section_id');
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function targetEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'target_enrollment_id');
    }
}
