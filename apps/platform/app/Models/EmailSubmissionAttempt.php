<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 0O.9A (ADR 0055 section 9.3): one row per REAL provider call --
 * tenant-owned, append-only (RLS + revoked UPDATE/DELETE). Bounded
 * metadata only: a closed outcome and failure code, never a credential,
 * an SMTP conversation or a provider response body.
 *
 * @property string $id
 * @property string $school_id
 * @property string $email_message_id
 * @property int $attempt_number
 * @property string $outcome
 * @property string|null $failure_code
 * @property string $provider
 * @property string|null $provider_message_id
 * @property int $duration_ms
 */
class EmailSubmissionAttempt extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmailMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
