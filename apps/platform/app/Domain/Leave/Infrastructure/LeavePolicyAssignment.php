<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.2): a leave policy on an EmploymentRecord (never a
 * User, role or assignment), effective-dated. No overlapping assignment of
 * one leave type on one employment; the only change is ending it (both
 * database-enforced). Employee evidence (E21-D9).
 *
 * @property string $id
 * @property string $school_id
 */
class LeavePolicyAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'leave_policy_assignments';

    /** HR.R1-C13 (GOV-6): nothing by mass assignment. Every column is stated by its writer. */
    protected $fillable = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'ended_at' => 'datetime'];
    }
}
