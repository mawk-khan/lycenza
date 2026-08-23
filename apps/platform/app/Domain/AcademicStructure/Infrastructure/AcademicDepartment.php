<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\AcademicDepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned, School-wide reference data (Phase 0D section 32).
 * Deliberately named AcademicDepartment, not the generic Department --
 * HR owns its own separate organizational-department concept,
 * App\Domain\HR\Infrastructure\Department (`hr_departments`, Phase
 * 8A.3), and the two must never collide or be conflated. This table
 * groups Subjects academically (curriculum/teaching structure); HR's
 * Department represents organizational/staff ownership (who manages
 * whom, workforce reporting). Neither references the other in Phase
 * 8A -- if a future checkpoint finds a real need to relate a Subject's
 * academic department to a staff member's HR department, that is an
 * explicit, reviewed addition then, not an assumed default now.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $code
 * @property string $status
 */
class AcademicDepartment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'academic_departments';

    protected $fillable = ['school_id', 'name', 'code', 'status'];

    protected static function newFactory(): AcademicDepartmentFactory
    {
        return AcademicDepartmentFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
