<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\GradeBandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lower-bound percentage threshold belonging to a `GradeScale`
 * (Phase 0H.4C). Deliberately stores ONLY `min_percentage` -- no upper
 * bound, no sequence column. A percentage P maps to the GradeBand with
 * the greatest `min_percentage <= P`; bands are naturally ordered by
 * `min_percentage` descending. A scale is complete (covers the whole
 * 0.00-100.00 domain) iff a band with `min_percentage = 0.00` exists.
 * Duplicate thresholds within one scale are rejected by
 * `grade_bands_min_percentage_unique` alone -- no PostgreSQL range
 * type or exclusion constraint is used anywhere in this module (ADR
 * 0035).
 *
 * Mutable (create/update/delete) ONLY while the parent GradeScale is
 * `draft`; frozen forever once the parent has ever been `active`.
 * `grade_scale_id` is deliberately excluded from `$fillable` -- the
 * parent is force-filled by `GradeScaleService` only, never
 * mass-assignable.
 *
 * @property string $id
 * @property string $school_id
 * @property string $grade_scale_id
 * @property string $min_percentage decimal string, 0.00-100.00
 * @property string $label human grade label, e.g. "A", "Pass"; never audited by value
 */
class GradeBand extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'grade_bands';

    protected $fillable = ['school_id', 'min_percentage', 'label'];

    protected static function newFactory(): GradeBandFactory
    {
        return GradeBandFactory::new();
    }

    protected function casts(): array
    {
        return ['min_percentage' => 'decimal:2'];
    }

    /** @return BelongsTo<GradeScale, $this> */
    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class);
    }
}
