<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\GradeScaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned, School-only reference catalogue (Phase 0H.4C): one
 * named mapping that converts a normalized percentage (0.00-100.00)
 * into a discrete grade outcome through ordered, lower-bound-only
 * percentage thresholds (`GradeBand`). Independent of the Examination
 * chain -- no Examination, ExaminationPaper, AcademicYear, GradeLevel or
 * Subject parent (ADR 0035).
 *
 * `App\Domain\Examinations\Application\GradeScaleService` is the ONLY
 * sanctioned write path. Lifecycle: draft -> active -> inactive, with
 * exactly three legal transitions (draft->active, active->inactive,
 * inactive->active); every other transition, including every no-op, is
 * illegal. GradeBands are freely mutable only while `draft`; the
 * instant a scale first becomes `active`, its bands are frozen forever
 * -- including while later `inactive` -- which is what makes
 * `inactive` structurally mean "was previously active," with no
 * separate `ever_activated` column needed.
 *
 * @property string $id
 * @property string $school_id
 * @property string $code uppercased/trimmed on assignment; immutable after create
 * @property string $name human label; deliberately NOT unique; mutable at any lifecycle stage
 * @property string $status draft|active|inactive
 */
class GradeScale extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The complete, closed status vocabulary, mirrored by the
     * database's own `grade_scales_status_check` CHECK constraint.
     */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $table = 'grade_scales';

    protected $fillable = ['school_id', 'code', 'name', 'status'];

    protected static function newFactory(): GradeScaleFactory
    {
        return GradeScaleFactory::new();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    /** @return HasMany<GradeBand, $this> */
    public function bands(): HasMany
    {
        return $this->hasMany(GradeBand::class)->orderByDesc('min_percentage');
    }
}
