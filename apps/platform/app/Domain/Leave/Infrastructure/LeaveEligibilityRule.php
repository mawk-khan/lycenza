<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HR.9 (ADR 0065 §28.3): one eligibility rule of a leave policy VERSION --
 * staff type, Employee Category and Department (each NULL = any value,
 * including none). Created only with its version, immutable, never deleted,
 * never ambiguous against another active version of the leave type (all
 * database-enforced). School configuration (tenant lifetime).
 *
 * @property string $id
 * @property string $school_id
 * @property string $leave_policy_id
 * @property string $leave_type_id
 * @property string|null $staff_type
 * @property string|null $employee_category_id
 * @property string|null $department_id
 */
class LeaveEligibilityRule extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_eligibility_rules';

    protected $fillable = ['school_id', 'leave_policy_id', 'leave_type_id', 'staff_type', 'employee_category_id', 'department_id', 'created_by_user_id'];
}
