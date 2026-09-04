<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Central/platform data (no school_id, no RLS) -- see the migration's
 * docblock and ADR 0037. `secret_encrypted` must never be returned by
 * any API response, logged, or audited -- see MfaEnrollmentService.
 *
 * @property string $id
 * @property string $user_id
 * @property string $type
 * @property string $secret_encrypted
 * @property string|null $label
 * @property string $status
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $last_used_at real wall-clock time of the last successful TOTP use -- never a replay counter (replay-correction, see ADR 0037)
 * @property int|null $last_used_totp_step google2fa TOTP period counter (`time() / 30`, NOT epoch seconds) accepted at the last successful verification -- the sole replay floor (replay-correction, see ADR 0037)
 */
#[Hidden(['secret_encrypted'])]
class UserMfaFactor extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['user_id', 'type', 'secret_encrypted', 'label', 'status'];

    protected function casts(): array
    {
        return [
            // Same reversible-secret convention as
            // WebhookEndpoint::secret_encrypted (CLAUDE.md rule 46).
            'secret_encrypted' => 'encrypted',
            'confirmed_at' => 'datetime',
            // Genuine wall-clock timestamp only -- never a TOTP period
            // counter. See `last_used_totp_step` for the replay floor.
            'last_used_at' => 'datetime',
            'last_used_totp_step' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
