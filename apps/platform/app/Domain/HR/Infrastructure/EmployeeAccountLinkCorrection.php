<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.R1-C8 (OD-HR-25 as amended): one request to correct an account link, and its one
 * decision. Nothing is effective until a second qualified person approves; the
 * approval itself voids the link (`trg_employee_account_link_corrections_apply`).
 * Written only by EmployeeAccountLinkCorrectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $account_link_id
 * @property string $reason
 * @property string $evidence_version
 * @property string $status requested, approved or refused
 * @property string $requested_by_user_id
 * @property Carbon $requested_at
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 */
class EmployeeAccountLinkCorrection extends Model
{
    use BelongsToSchool;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
