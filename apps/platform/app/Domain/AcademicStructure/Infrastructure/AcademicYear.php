<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned data (Phase 0D sections 13-18). Only one row per School
 * may have `status = 'active'` -- enforced by a PostgreSQL partial
 * unique index (see the migration), not application code alone. See
 * App\Domain\AcademicStructure\Application\ActivateAcademicYear for the
 * transactional, concurrency-safe transition, and
 * App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver
 * for the one authoritative way to find "the" active year.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $code
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status draft|active|closed|archived
 */
class AcademicYear extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'academic_years';

    protected $fillable = ['school_id', 'name', 'code', 'starts_on', 'ends_on', 'status'];

    protected static function newFactory(): AcademicYearFactory
    {
        return AcademicYearFactory::new();
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<AcademicTerm, $this> */
    public function terms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}
