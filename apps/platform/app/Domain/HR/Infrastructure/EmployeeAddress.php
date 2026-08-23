<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8A.2 -- one of an Employee's 1:N Restricted-tier addresses
 * (docs/modules/HR.md). An Employee may have at most one address of
 * each of `current`/`permanent`/`mailing` (database-enforced, see the
 * migration's partial unique index), but arbitrarily many `other`
 * addresses.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\EmployeeAddressService -- never create/
 * update this model directly with a raw `create()`/`update()` call
 * outside a test, since the service is what verifies Employee
 * ownership and strips caller-controlled `school_id`/`employee_id`.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $address_type current|permanent|mailing|other
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state_region
 * @property string|null $postal_code
 * @property string $country_code
 */
class EmployeeAddress extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_addresses';

    /** Domain-stable values -- not School-configurable reference data. */
    public const array TYPES = ['current', 'permanent', 'mailing', 'other'];

    protected $fillable = [
        'school_id',
        'employee_id',
        'address_type',
        'address_line1',
        'address_line2',
        'city',
        'state_region',
        'postal_code',
        'country_code',
    ];

    protected static function newFactory(): EmployeeAddressFactory
    {
        return EmployeeAddressFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
