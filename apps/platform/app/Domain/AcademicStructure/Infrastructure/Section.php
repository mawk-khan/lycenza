<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned data (Phase 0D sections 25-28). Belongs to exactly ONE
 * AcademicYear -- "Grade 5 A" in 2026-27 and "Grade 5 A" in 2027-28 are
 * distinct rows, never the same row mutated/reused across years
 * (section 28's historical-safety rule). `capacity` is advisory only
 * (section 27) -- no admission-blocking logic reads it yet.
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $name
 * @property string $code
 * @property int|null $capacity
 * @property string $status
 */
class Section extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'sections';

    protected $fillable = [
        'school_id', 'academic_year_id', 'campus_id', 'grade_level_id',
        'name', 'code', 'capacity', 'status',
    ];

    protected static function newFactory(): SectionFactory
    {
        return SectionFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }
}
