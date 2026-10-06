<?php

namespace App\Domain\Library\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * OPF.4 (ADR 0067 §17): Finance evidence of one Library fine -- one per loan
 * and kind (v1 `overdue`), naming the policy version, fee head, academic year
 * and the FEE event charge, with the due/check-in instants, the days and the
 * amount it was derived from (re-derived by the database on insert).
 * Insert-only; a void is a separate LibraryFineVoid row. Written only by
 * LibraryFineService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $library_loan_id
 * @property string $student_id
 * @property string $kind
 * @property string $library_fine_policy_id
 * @property string $fee_head_id
 * @property string $academic_year_id
 * @property Carbon $due_at
 * @property Carbon $checked_in_at
 * @property int $overdue_days
 * @property int $chargeable_days
 * @property string $amount
 * @property string $currency
 * @property string $charge_id
 * @property string|null $assessed_by_user_id
 * @property Carbon|null $created_at
 */
class LibraryFine extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const KIND_OVERDUE = 'overdue';

    public const UPDATED_AT = null;

    protected $table = 'library_fines';

    protected $fillable = [
        'id', 'school_id', 'library_loan_id', 'student_id', 'kind', 'library_fine_policy_id', 'fee_head_id', 'academic_year_id',
        'due_at', 'checked_in_at', 'overdue_days', 'chargeable_days', 'amount', 'currency', 'charge_id', 'assessed_by_user_id',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'checked_in_at' => 'datetime', 'overdue_days' => 'integer', 'chargeable_days' => 'integer'];
    }

    /** @return BelongsTo<LibraryLoan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(LibraryLoan::class, 'library_loan_id');
    }

    /** @return HasOne<LibraryFineVoid, $this> */
    public function void(): HasOne
    {
        return $this->hasOne(LibraryFineVoid::class, 'library_fine_id');
    }
}
