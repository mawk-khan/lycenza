<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned audit ledger (ADR 0017), RLS-protected AND append-only
 * at the database privilege level -- see the migration and ADR 0021.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $event_type
 */
class SchoolAuditEvent extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    const UPDATED_AT = null;

    protected $fillable = [
        'school_id', 'occurred_at', 'actor_user_id', 'event_type',
        'subject_type', 'subject_id', 'request_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
