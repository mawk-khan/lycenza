<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned, School-wide reference data (Phase 0D sections 29-33,
 * 62). `academic_department_id` is optional (section 33).
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $academic_department_id
 * @property string $name
 * @property string $code
 * @property string|null $short_name
 * @property string $subject_type core|elective|co_scholastic|language|other
 * @property string $status
 */
class Subject extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'subjects';

    protected $fillable = [
        'school_id', 'academic_department_id', 'name', 'code',
        'short_name', 'subject_type', 'status',
    ];

    protected static function newFactory(): SubjectFactory
    {
        return SubjectFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<AcademicDepartment, $this> */
    public function academicDepartment(): BelongsTo
    {
        return $this->belongsTo(AcademicDepartment::class);
    }
}
