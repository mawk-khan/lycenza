<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeEmergencyContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8A.2 -- one of an Employee's 1:N Restricted-tier emergency
 * contacts (docs/modules/HR.md). An emergency contact is an external
 * person -- never required to be a User/Guardian/Employee or any other
 * School OS identity; there is no generic Party/Person subsystem here.
 *
 * At most one `is_primary = true` row per Employee (database-enforced,
 * see the migration's partial unique index). The only sanctioned way to
 * promote a contact to primary is
 * App\Domain\HR\Application\EmployeeEmergencyContactService::setPrimary(),
 * which demotes the previous primary and promotes the new one in one
 * transaction -- never toggle `is_primary` directly.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $name
 * @property string|null $relationship
 * @property string $phone
 * @property string|null $alternate_phone
 * @property string|null $email
 * @property bool $is_primary
 */
class EmployeeEmergencyContact extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_emergency_contacts';

    protected $fillable = [
        'school_id',
        'employee_id',
        'name',
        'relationship',
        'phone',
        'alternate_phone',
        'email',
        'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    protected static function newFactory(): EmployeeEmergencyContactFactory
    {
        return EmployeeEmergencyContactFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
