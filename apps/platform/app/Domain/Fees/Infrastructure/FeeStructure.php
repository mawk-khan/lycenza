<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * FEE.1 (ADR 0062 §7): the fees of one AcademicYear x GradeLevel, optionally
 * for one Campus (NULL campus = the School-wide default for that grade).
 * `draft -> active -> retired` only, database-enforced; immutable once it
 * leaves draft. `App\Domain\Fees\Application\FeeStructureService` owns
 * every write.
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $grade_level_id
 * @property string|null $campus_id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property string|null $supersedes_fee_structure_id
 * @property string|null $created_by_user_id
 * @property Carbon|null $activated_at
 * @property string|null $activated_by_user_id
 * @property Carbon|null $retired_at
 * @property string|null $retired_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FeeStructure extends Model
{
    use BelongsToSchool, GeneratesUuidV7, NormalizesCode;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_RETIRED];

    protected $table = 'fee_structures';

    protected $fillable = [
        'school_id', 'academic_year_id', 'grade_level_id', 'campus_id', 'code', 'name', 'status',
        'supersedes_fee_structure_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return HasMany<FeeStructureLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FeeStructureLine::class);
    }
}
