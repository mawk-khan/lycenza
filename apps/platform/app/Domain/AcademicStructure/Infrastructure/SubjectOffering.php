<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\SubjectOfferingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned data (Phase 0D sections 37-40) -- the academic-offering
 * layer: which Subjects a GradeLevel offers, within a Campus, for one
 * specific AcademicYear. AcademicYear-specific by construction (section
 * 38) -- never a global Grade->Subject map that would rewrite previous
 * years' history.
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $subject_id
 * @property bool $is_required
 * @property int|null $sequence
 * @property int|null $weekly_periods_target
 * @property string $status
 */
class SubjectOffering extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'subject_offerings';

    protected $fillable = [
        'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'subject_id',
        'is_required', 'sequence', 'weekly_periods_target', 'status',
    ];

    protected static function newFactory(): SubjectOfferingFactory
    {
        return SubjectOfferingFactory::new();
    }

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
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

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
