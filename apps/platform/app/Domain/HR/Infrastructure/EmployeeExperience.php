<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeExperienceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.6 -- one of an Employee's 1:N Restricted-tier records of
 * professional experience OUTSIDE this School's own employment
 * (docs/modules/HR.md entity model: `EmployeeExperience`). Backed by
 * the `employee_experience_records` table -- deliberately named apart
 * from `employment_records` so the two are never confused: this row
 * never represents employment with this School tenant (that is
 * `EmploymentRecord`'s exclusive concern), only externally-reported
 * professional history. No verification model -- see the migration's
 * docblock for why.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\EmployeeExperienceService -- never create/
 * update this model directly outside a test.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $organization
 * @property string $job_title
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $description
 * @property string|null $location
 * @property string|null $country_code
 */
class EmployeeExperience extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_experience_records';

    protected $fillable = [
        'school_id',
        'employee_id',
        'organization',
        'job_title',
        'starts_on',
        'ends_on',
        'description',
        'location',
        'country_code',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function newFactory(): EmployeeExperienceFactory
    {
        return EmployeeExperienceFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
