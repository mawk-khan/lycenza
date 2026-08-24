<?php

namespace App\Domain\HR\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8A.3 -- HR's organizational-department reference data
 * (docs/modules/HR.md "Department and Position strategy"; canonical
 * terminology: table `hr_departments`, DELIBERATELY never bare
 * `Department` colliding with anything, and never conflated with
 * App\Domain\AcademicStructure\Infrastructure\AcademicDepartment --
 * that table groups Subjects academically, a different concept from
 * staff organizational ownership. See the class docblock there for the
 * matching cross-reference.
 *
 * School-wide when `campus_id` is null, Campus-scoped when set.
 * Optionally hierarchical via `parent_department_id` (e.g. "Accounts"
 * under "Administration"). No delete endpoint -- Departments are
 * reference data a future EmployeeAssignment will hold historical
 * references to (docs/modules/HR.md rule 73's reference-entity
 * deactivation pattern); `status` (`active`/`inactive`) is how a
 * Department is retired.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\DepartmentService -- never create/update
 * this model directly outside a test, since the service validates
 * Campus/parent School ownership and hierarchy-cycle safety.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $campus_id
 * @property string|null $parent_department_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string $status active|inactive
 */
class Department extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'hr_departments';

    protected $fillable = [
        'school_id',
        'campus_id',
        'parent_department_id',
        'name',
        'code',
        'description',
        'status',
    ];

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_department_id');
    }

    /** @return HasMany<Department, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_department_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
