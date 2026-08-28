<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\ElectiveGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned reference data (Phase 1F.1, architecture doc §8) -- a
 * named group of mutually-exclusive `SubjectOffering`s within one
 * AcademicYear/Campus/GradeLevel context (e.g. "French OR Spanish").
 * Membership is `SubjectOffering.elective_group_id` (a single nullable
 * FK, not a pivot table) -- an Offering belongs to at most one group.
 *
 * No `status` column -- no repository evidence of an independent group
 * lifecycle (architecture doc §8/§26); delete safety comes entirely
 * from restrict-on-delete FKs on this model's own parents and on
 * `subject_offerings.elective_group_id`.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $name
 * @property string $code
 */
class ElectiveGroup extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'elective_groups';

    protected $fillable = [
        'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'name', 'code',
    ];

    protected static function newFactory(): ElectiveGroupFactory
    {
        return ElectiveGroupFactory::new();
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

    /** @return HasMany<SubjectOffering, $this> */
    public function subjectOfferings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }
}
