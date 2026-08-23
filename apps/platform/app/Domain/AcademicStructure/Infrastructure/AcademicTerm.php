<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\AcademicTermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned data (Phase 0D sections 19-21). Belongs to exactly one
 * AcademicYear; dates must fall within the parent year's range and
 * terms within one year must not overlap -- both enforced by
 * App\Domain\AcademicStructure\Application\CreateAcademicTerm, not this
 * model.
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $name
 * @property string $code
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $sequence
 */
class AcademicTerm extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'academic_terms';

    protected $fillable = ['school_id', 'academic_year_id', 'name', 'code', 'starts_on', 'ends_on', 'sequence'];

    protected static function newFactory(): AcademicTermFactory
    {
        return AcademicTermFactory::new();
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
