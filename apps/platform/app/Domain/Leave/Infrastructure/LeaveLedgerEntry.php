<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.5): one append-only ledger entry -- a kind and a
 * POSITIVE number of integer half-day units; the sign comes from the kind
 * (and `direction` for an adjustment). The balance is the sum of a key's
 * entries; the database refuses any entry that would make it negative.
 * Written only by LeaveLedgerWriter.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveLedgerEntry extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const KINDS = ['allocation', 'adjustment', 'consumption', 'reversal', 'carry_forward_in', 'carry_forward_out', 'expiry'];

    /** Kinds that increase the balance (an adjustment depends on its direction). */
    public const CREDIT_KINDS = ['allocation', 'reversal', 'carry_forward_in'];

    public const ADJUSTMENT_REASONS = ['entitlement_change', 'allocation_correction', 'administrative_correction'];

    public const UPDATED_AT = null;

    protected $table = 'leave_ledger_entries';

    protected $fillable = ['school_id', 'employment_record_id', 'leave_type_id', 'tracks_balance', 'leave_year_id', 'leave_policy_assignment_id', 'kind', 'direction', 'units', 'reason_code', 'allocation_run_id', 'reverses_entry_id', 'source_type', 'source_id', 'actor_user_id'];

    protected function casts(): array
    {
        return ['units' => 'integer', 'tracks_balance' => 'boolean'];
    }
}
