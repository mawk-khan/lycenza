<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.4: one receivable obligation against a Student, immediately
 * posted to the ledger at creation (no draft state -- see the
 * `create_charges_table` migration's docblock). Persistence +
 * relationships only, matching `LedgerAccount`/`JournalEntry`'s own
 * "no business behavior on the model" discipline (CLAUDE.md rule 3) --
 * `App\Domain\Fees\Application\ChargeService` owns assessment and
 * cancellation.
 *
 * Only ONE legitimate UPDATE path exists
 * (`cancelled_at`/`cancellation_journal_entry_id`, set together,
 * exactly once) -- every other column is DATABASE-immutable after
 * insert (`charges_immutability_trigger`, see the `create_charges_table`
 * migration), not merely an Application-layer convention. Not
 * `TenantRls::makeAppendOnly()`'d like `JournalEntry`/`JournalLine`
 * (that would also revoke the one legitimate UPDATE path) -- DELETE
 * alone is revoked (`TenantRls::revokeDelete()`), and UPDATE is
 * database-narrowed by the trigger instead of blanket-revoked.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $description
 * @property string $amount
 * @property string $currency
 * @property Carbon|null $due_date
 * @property string $receivable_ledger_account_id
 * @property string $revenue_ledger_account_id
 * @property string $journal_entry_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_journal_entry_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Charge extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'charges';

    protected $fillable = [
        'school_id', 'student_id', 'academic_year_id', 'description', 'amount', 'currency',
        'due_date', 'receivable_ledger_account_id', 'revenue_ledger_account_id', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ChargeFactory
    {
        return ChargeFactory::new();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
