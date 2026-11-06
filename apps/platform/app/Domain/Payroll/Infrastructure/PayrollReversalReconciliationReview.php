<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23): an independent person's statement that they read
 * the real chronology of a post-posting reversal and accept it as reconciled. It
 * is never an approval, certifies nothing about the original decision and changes
 * no run. Final and append-only: there is no correction path.
 *
 * @property string $id
 * @property string $school_id
 * @property string $reconciliation_id
 * @property string $evidence_version
 * @property string $statement_code
 * @property int $unlinked_employee_count
 * @property string $reviewed_by_user_id
 */
class PayrollReversalReconciliationReview extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    public const STATEMENT_REVERSAL_IMPACT_REVIEWED = 'reversal_impact_reviewed_v1';

    protected $fillable = ['school_id', 'reconciliation_id', 'evidence_version', 'statement_code', 'unlinked_employee_count', 'reviewed_by_user_id'];

    protected function casts(): array
    {
        return ['unlinked_employee_count' => 'integer'];
    }
}
