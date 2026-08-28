<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8A closure correction -- School-configurable Employee
 * classification (Teaching/Non-Teaching/Contract/Visiting, ...),
 * reference data, never a hardcoded enum (docs/modules/HR.md canonical
 * terminology). School-wide, mirrors Position's exact shape (App\Domain\HR\Infrastructure\Position).
 * No delete endpoint -- `status` (`active`/`inactive`) is how a
 * Category is retired once an `employment_records.employee_category_id`
 * may reference it historically.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\EmployeeCategoryService -- never create/
 * update this model directly outside a test.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $code
 * @property string $status active|inactive
 */
class EmployeeCategory extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'employee_categories';

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'status',
    ];

    protected static function newFactory(): EmployeeCategoryFactory
    {
        return EmployeeCategoryFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
