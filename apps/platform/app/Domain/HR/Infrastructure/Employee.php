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

/**
 * Phase 8A.1 -- the Employee core aggregate (docs/modules/HR.md, ADR
 * 0028). Deliberately carries no employment/assignment/personal-detail
 * fields -- those belong to later checkpoints (8A.2 Personal Details,
 * 8A.4 Employment Records & Assignments). This is the minimal identity
 * record every later HR checkpoint builds on.
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
 * @property string $record_status active|archived -- this Employee
 *                                 row's own existence state, NOT employment/account status
 *                                 (docs/modules/HR.md's state responsibility matrix)
 */
class Employee extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employees';

    protected $fillable = ['school_id', 'user_id', 'employee_number', 'full_name', 'record_status'];

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

    public function isActive(): bool
    {
        return $this->record_status === 'active';
    }
}
