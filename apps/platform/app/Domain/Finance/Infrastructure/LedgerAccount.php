<?php

namespace App\Domain\Finance\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\LedgerAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 0G.1 (ADR 0030, docs/modules/FINANCE.md "Account model"): a
 * chart-of-accounts entry. System-managed only in 0G.1 -- `is_system`
 * rows are non-deletable/non-renameable via any future School-facing
 * UI. Reference/configuration data, not a posting -- unlike
 * JournalEntry/JournalLine this table is NOT append-only (a School
 * admin may rename an account or deactivate it), so ordinary
 * timestamps (including `updated_at`) apply.
 *
 * No posting/balance methods here on purpose -- 0G.2's Posting &
 * Reversal Application Services own that behavior; this model is
 * persistence + relationships only (CLAUDE.md rule 3's "controllers
 * stay thin" principle, extended to this checkpoint's models).
 *
 * @property string $id
 * @property string $school_id
 * @property string $code
 * @property string $name
 * @property string $type asset|liability|equity|income|expense
 * @property string $currency
 * @property bool $is_system
 * @property string $status active|inactive
 */
class LedgerAccount extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'ledger_accounts';

    protected $fillable = ['school_id', 'code', 'name', 'type', 'currency', 'is_system', 'status'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    protected static function newFactory(): LedgerAccountFactory
    {
        return LedgerAccountFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
