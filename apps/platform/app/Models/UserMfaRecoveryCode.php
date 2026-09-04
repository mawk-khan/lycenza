<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Central/platform data (no school_id, no RLS) -- see the migration's
 * docblock and ADR 0037. `code_hash` must never be returned by any API
 * response, logged, or audited.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property Carbon|null $consumed_at
 */
#[Hidden(['code_hash'])]
class UserMfaRecoveryCode extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['user_id', 'code_hash'];

    protected function casts(): array
    {
        return [
            // Verify-only, single-use -- never needs to round-trip to
            // plaintext, so `hashed` (same treatment as
            // users.password), not `encrypted`.
            'code_hash' => 'hashed',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
