<?php

namespace App\Domain\Transport\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OPF.1 (ADR 0067 §14): insert-only provenance of the FEE optional selection
 * a Student Transport assignment recorded intent for, in one academic year.
 * Unique per assignment x year; database-checked against the selection's
 * Student, year and fee head; never updated or deleted by the runtime role.
 * Written only by TransportFeeSelectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $transport_student_assignment_id
 * @property string $academic_year_id
 * @property string $fee_head_id
 * @property string $fee_optional_selection_id
 * @property string $link_reason
 * @property string $selection_outcome
 * @property Carbon|null $created_at
 */
class TransportFeeSelection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const REASON_ASSIGNMENT = 'assignment';

    public const REASON_CARRY_FORWARD = 'carry_forward';

    public const UPDATED_AT = null;

    protected $table = 'transport_fee_selections';

    protected $fillable = [
        'school_id', 'transport_student_assignment_id', 'academic_year_id', 'fee_head_id',
        'fee_optional_selection_id', 'link_reason', 'selection_outcome',
    ];

    /** @return BelongsTo<TransportStudentAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TransportStudentAssignment::class, 'transport_student_assignment_id');
    }
}
