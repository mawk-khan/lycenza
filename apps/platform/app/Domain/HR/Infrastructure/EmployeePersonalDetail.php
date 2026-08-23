<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeePersonalDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.2 -- the Employee's 1:1 Restricted-tier personal-details
 * extension (docs/modules/HR.md). Deliberately carries no government
 * identifiers, bank details, tax declarations, or health data -- those
 * stay Highly Sensitive and unmodeled in Phase 8A.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\EmployeePersonalDetailService::setDetails(),
 * which enforces the 1:1 invariant via `updateOrCreate()` -- never
 * create this model directly with a raw `create()` call outside a test.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property Carbon|null $date_of_birth
 * @property string|null $nationality
 * @property string|null $marital_status
 * @property string|null $preferred_language
 * @property string|null $personal_email
 * @property string|null $personal_phone
 * @property string|null $alternate_phone
 */
class EmployeePersonalDetail extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_personal_details';

    protected $fillable = [
        'school_id',
        'employee_id',
        'date_of_birth',
        'nationality',
        'marital_status',
        'preferred_language',
        'personal_email',
        'personal_phone',
        'alternate_phone',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    protected static function newFactory(): EmployeePersonalDetailFactory
    {
        return EmployeePersonalDetailFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
