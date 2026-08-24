<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8A.3 -- HR's job/organizational-title reference data
 * (docs/modules/HR.md "Department and Position strategy"; canonical
 * terminology: "a job/organizational title (Teacher, Accountant,
 * Principal, Librarian, Driver, ...) -- **not** an authorization
 * role"). School-wide only -- deliberately not Campus-scoped (which
 * Campus an Assignment happens at is the Assignment's own concern,
 * 8A.4).
 *
 * Position != Role/Capability/Membership (docs/modules/HR.md principle
 * 2.4): this model carries no relationship to
 * `App\Models\Role`/`App\Models\Capability`/`App\Models\MembershipRoleAssignment`
 * whatsoever, and App\Domain\HR\Application\PositionService creates,
 * updates, archives, and reactivates a Position without ever touching
 * an authorization table as a side effect. Granting a Principal's
 * actual application access remains Identity & Access's existing,
 * completely separate mechanism (assigning a school-scoped Role via
 * `MembershipRoleAssignment`).
 *
 * No delete endpoint -- Positions are reference data a future
 * EmployeeAssignment will hold historical references to;
 * `status` (`active`/`inactive`) is how a Position is retired.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\PositionService -- never create/update
 * this model directly outside a test.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string $status active|inactive
 */
class Position extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'positions';

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'description',
        'status',
    ];

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
