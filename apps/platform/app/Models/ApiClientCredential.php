<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.3 (ADR 0049 section 5): one credential of a partner API client.
 * Only a SHA-256 hash of the 256-bit secret is stored (`secret_hash`,
 * hidden); the cleartext is shown once at issue/rotation and never again.
 * Always expires; revocation and supersession are final
 * (database-enforced).
 *
 * @property string $id
 * @property string $api_client_id
 * @property string $school_id
 * @property string $key_id
 * @property string $secret_hash
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $superseded_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 */
class ApiClientCredential extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['api_client_id', 'school_id', 'key_id', 'secret_hash', 'issued_at', 'expires_at'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'superseded_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<ApiClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
