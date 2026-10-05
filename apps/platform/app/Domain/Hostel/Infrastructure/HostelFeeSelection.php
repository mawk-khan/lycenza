<?php

namespace App\Domain\Hostel\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OPF.2 (ADR 0067 §15): insert-only provenance of the FEE optional selection
 * a Student Hostel residency recorded intent for, in one academic year.
 * Unique per residency x year; database-checked against the selection's
 * Student, year and fee head; never updated or deleted by the runtime role.
 * Written only by HostelFeeSelectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $hostel_residency_assignment_id
 * @property string $academic_year_id
 * @property string $fee_head_id
 * @property string $fee_optional_selection_id
 * @property string $mapping_scope room|hostel
 * @property string $link_reason
 * @property string $selection_outcome
 * @property Carbon|null $created_at
 */
class HostelFeeSelection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const REASON_RESIDENCY = 'residency';

    public const REASON_CARRY_FORWARD = 'carry_forward';

    public const SCOPE_ROOM = 'room';

    public const SCOPE_HOSTEL = 'hostel';

    public const UPDATED_AT = null;

    protected $table = 'hostel_fee_selections';

    protected $fillable = [
        'school_id', 'hostel_residency_assignment_id', 'academic_year_id', 'fee_head_id',
        'fee_optional_selection_id', 'mapping_scope', 'link_reason', 'selection_outcome',
    ];

    /** @return BelongsTo<HostelResidencyAssignment, $this> */
    public function residency(): BelongsTo
    {
        return $this->belongsTo(HostelResidencyAssignment::class, 'hostel_residency_assignment_id');
    }
}
