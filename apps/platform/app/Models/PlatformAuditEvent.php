<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Central/platform audit ledger (ADR 0017). Append-only: this table's
 * runtime-role grants have UPDATE/DELETE revoked at the database level
 * (see the migration and ADR 0021) -- not just application discipline.
 * updated_at is intentionally absent; only created_at exists.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $event_type
 */
class PlatformAuditEvent extends Model
{
    use GeneratesUuidV7;

    const UPDATED_AT = null;

    protected $fillable = [
        'occurred_at', 'actor_user_id', 'event_type', 'subject_type',
        'subject_id', 'ip_address', 'user_agent', 'request_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
