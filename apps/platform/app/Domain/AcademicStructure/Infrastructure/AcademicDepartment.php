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
 * a future HR module owns its own organizational-department concept
 * and must never collide with this one.
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
