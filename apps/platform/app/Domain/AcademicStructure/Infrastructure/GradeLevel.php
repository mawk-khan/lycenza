<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\GradeLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned, School-wide reference data (Phase 0D sections 22-24,
 * 62) -- not Campus- or AcademicYear-scoped. `sequence` is the
 * explicit pedagogical ordering (Nursery, LKG, UKG, Grade 1, ...);
 * never inferred from `name`/`code`.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $code
 * @property int $sequence
 * @property string|null $education_stage
 * @property string $status
 */
class GradeLevel extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'grade_levels';

    protected $fillable = ['school_id', 'name', 'code', 'sequence', 'education_stage', 'status'];

    protected static function newFactory(): GradeLevelFactory
    {
        return GradeLevelFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
