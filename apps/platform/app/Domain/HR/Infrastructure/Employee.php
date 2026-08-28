<?php

namespace App\Domain\HR\Infrastructure;

use App\Domain\HR\Application\Exceptions\EmployeeNumberIsImmutableException;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phase 8A.1 -- the Employee core aggregate (docs/modules/HR.md, ADR
 * 0028). Deliberately carries no employment/assignment fields --
 * organizational placement and employment lifecycle live entirely on
 * EmploymentRecord/EmployeeAssignment (Phase 8A.4). This is the
 * minimal identity record every later HR checkpoint builds on. Phase
 * 8A.2 adds its Restricted-tier personal-details/address/emergency-
 * contact extensions (relations below) without adding any new column
 * here -- Employee itself stays exactly as small as 8A.1 left it. 8A.6
 * adds Qualification/Experience/Certification relations on the same
 * principle -- no denormalized summary column (e.g.
 * `highest_qualification`) is added here; those are derived from the
 * child records, not stored.
 *
 * `employee_number` is immutable once set (see booted() below) -- the
 * only sanctioned way to create an Employee is
 * App\Domain\HR\Application\EmployeeService::create(), which allocates
 * the number through App\Domain\HR\Application\EmployeeNumberAllocator.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $user_id
 * @property string $employee_number
 * @property string $full_name
 * @property string|null $work_email Directory-tier work contact (Phase 8A closure
 *                                   correction) -- unique per School where not null
 *                                   (`employees_work_email_unique`); never substituted
 *                                   with `EmployeePersonalDetail.personal_email`.
 * @property string|null $work_phone Directory-tier work contact, no uniqueness required.
 * @property string $record_status active|archived -- this Employee
 *                                 row's own existence state, NOT employment/account status
 *                                 (docs/modules/HR.md's state responsibility matrix)
 */
class Employee extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employees';

    protected $fillable = ['school_id', 'user_id', 'employee_number', 'full_name', 'work_email', 'work_phone', 'record_status'];

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (self $employee): void {
            if ($employee->isDirty('employee_number')) {
                throw new EmployeeNumberIsImmutableException(
                    (string) $employee->getOriginal('employee_number'),
                    (string) $employee->employee_number,
                );
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<EmployeePersonalDetail, $this> */
    public function personalDetail(): HasOne
    {
        return $this->hasOne(EmployeePersonalDetail::class);
    }

    /** @return HasMany<EmployeeAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class);
    }

    /** @return HasMany<EmployeeEmergencyContact, $this> */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class);
    }

    /**
     * @return HasMany<EmploymentRecord, $this>
     *
     * Deliberately no direct `assignments()` relation on Employee --
     * docs/modules/HR.md's entity model routes Assignment through
     * EmploymentRecord, not directly off Employee; reach assignments
     * via `$employmentRecord->assignments`.
     */
    public function employmentRecords(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class);
    }

    /** @return HasMany<EmployeeQualification, $this> */
    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class);
    }

    /** @return HasMany<EmployeeExperience, $this> */
    public function experienceRecords(): HasMany
    {
        return $this->hasMany(EmployeeExperience::class);
    }

    /** @return HasMany<EmployeeCertification, $this> */
    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** @return HasMany<EmployeeNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(EmployeeNote::class);
    }

    public function isActive(): bool
    {
        return $this->record_status === 'active';
    }
}
