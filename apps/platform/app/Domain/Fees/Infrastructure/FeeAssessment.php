<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.2 (ADR 0062 §11): the one live link between a Student's fee head and
 * billing period in an AcademicYear and the charge that billed it
 * (`fee_assessments_one_live_per_period`). Append-only except the one-time
 * void, which cancels the charge in the same transaction.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $fee_head_id
 * @property string $billing_period_key
 * @property string $fee_structure_id
 * @property string $fee_structure_line_id
 * @property string $fee_structure_installment_id
 * @property string $student_enrollment_id
 * @property string|null $fee_assessment_run_id
 * @property string $charge_id
 * @property string|null $created_by_user_id
 * @property Carbon|null $voided_at
 * @property string|null $voided_by_user_id
 * @property string|null $void_reason
 * @property Carbon $created_at
 */
class FeeAssessment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'fee_assessments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'voided_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->voided_at === null;
    }
}
