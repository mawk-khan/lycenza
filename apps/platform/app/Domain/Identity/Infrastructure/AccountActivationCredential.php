<?php

namespace App\Domain\Identity\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.12B (ADR 0059 sections 5, 10): the one-time credential that lets
 * a credential-less, operator-provisioned bootstrap account set its first
 * password. Identity-level (no School, no RLS); used ONLY by
 * App\Domain\Identity\Application\Staff (architecture-tested). Holds the
 * SHA-256 of the secret -- never the secret, a URL or a Host.
 *
 * @property string $id
 * @property string $selector
 * @property string $user_id
 * @property string $secret_hash
 * @property int $credential_version
 * @property string $created_via
 * @property Carbon $created_at
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $invalidated_at
 * @property string|null $invalidation_reason
 */
class AccountActivationCredential extends Model
{
    use GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credential_version' => 'integer',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->consumed_at === null && $this->invalidated_at === null && $this->expires_at->isFuture();
    }
}
