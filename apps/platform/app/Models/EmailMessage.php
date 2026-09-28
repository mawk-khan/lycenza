<?php

namespace App\Models;

use App\Support\Email\EmailKind;
use App\Support\Email\EmailPurpose;
use App\Support\Email\EmailState;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\AllowsIdentityLevelRows;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.9A (ADR 0055 section 9): one logical email to ONE recipient --
 * the authoritative transport state. Tenant-owned (forced RLS). Written
 * only by App\Support\Email\OutboundEmailGateway and the submission and
 * event services; the migration's triggers enforce the state graph,
 * identity immutability and the content purge.
 *
 * `id` (UUIDv7) is the internal message identifier. The RFC 5322
 * Message-ID (`rfc_message_id`) and the provider idempotency key are
 * DERIVED from it explicitly (App\Support\Email\EmailMessageIdentity);
 * the provider's own id is `provider_message_id`, set once on acceptance.
 *
 * @property string $id
 * @property string|null $school_id
 * @property EmailPurpose $purpose
 * @property EmailKind $kind
 * @property string $source_type
 * @property string $source_id
 * @property string $recipient_encrypted
 * @property string $from_mailbox
 * @property string $from_display_name
 * @property string $subject
 * @property array<string, mixed>|null $sealed_content
 * @property Carbon|null $content_purged_at
 * @property string|null $rfc_message_id
 * @property EmailState $status
 * @property string|null $status_code
 * @property int $attempts
 * @property int $retry_base
 * @property int $manual_retries
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $processing_lease_expires_at
 * @property Carbon $expires_at
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $bounced_at
 * @property Carbon|null $complained_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $last_event_at
 * @property Carbon $created_at
 */
class EmailMessage extends Model implements AllowsIdentityLevelRows
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected $hidden = ['recipient_encrypted', 'sealed_content'];

    protected function casts(): array
    {
        return [
            'purpose' => EmailPurpose::class,
            'kind' => EmailKind::class,
            'status' => EmailState::class,
            'recipient_encrypted' => 'encrypted',
            'sealed_content' => 'encrypted:array',
            'content_purged_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'processing_lease_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /** @return HasMany<EmailSubmissionAttempt, $this> */
    public function submissionAttempts(): HasMany
    {
        return $this->hasMany(EmailSubmissionAttempt::class);
    }

    public function recipient(): string
    {
        return $this->recipient_encrypted;
    }
}
