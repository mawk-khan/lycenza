<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.R1-C7 (OD-HR-25): that an account was linked to this Employee. The current
 * link stays `employees.user_id`; this is its history, written by a trigger on that
 * column. The runtime role only reads it. A row is never removed: a correction marks
 * it, through EmployeeAccountLinkCorrectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $user_id
 * @property string $origin recorded, or cutover (known at cutover; nothing earlier is known)
 * @property Carbon $linked_at both recorded and effective: a link cannot be backdated
 * @property string|null $linked_by_user_id
 * @property Carbon|null $ended_at
 * @property string|null $ended_by_user_id
 * @property Carbon|null $voided_at
 * @property string|null $voided_by_user_id
 * @property string|null $void_reason
 */
class EmployeeAccountLink extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'ended_at' => 'datetime', 'voided_at' => 'datetime'];
    }
}
