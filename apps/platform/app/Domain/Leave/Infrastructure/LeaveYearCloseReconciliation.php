<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.2 (ADR 0065 §23.9): the correction written when an approved request
 * whose consumption sits in a closed year is cancelled -- the reversed
 * units split into extra carried and extra lapsed units under the ORIGINAL
 * close's policy terms. One per reversal; append-only. The original close
 * is never rewritten.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveYearCloseReconciliation extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_year_close_reconciliations';

    protected $fillable = ['school_id', 'year_close_item_id', 'leave_request_id', 'reversal_entry_id', 'units', 'carried_delta', 'lapsed_delta', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['units' => 'integer', 'carried_delta' => 'integer', 'lapsed_delta' => 'integer'];
    }
}
