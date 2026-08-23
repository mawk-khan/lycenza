<?php

namespace App\Models;

use App\Support\Idempotency\IdempotencyStatus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned data (RLS-protected). See the migration's docblock and
 * App\Support\Idempotency\IdempotencyGuard, which is the ONLY sanctioned
 * writer of this model -- do not create/update rows directly elsewhere.
 *
 * @property string $id
 * @property string $school_id
 * @property string $actor_type
 * @property string $actor_id
 * @property string $route_action
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property string $request_method
 * @property string $status
 * @property int|null $response_status
 * @property array|null $response_headers
 * @property array|null $response_body
 * @property Carbon|null $completed_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class ApiIdempotencyKey extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_headers' => 'array',
            'response_body' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function statusEnum(): IdempotencyStatus
    {
        return IdempotencyStatus::from($this->status);
    }
}
