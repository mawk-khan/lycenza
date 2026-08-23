<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned data (RLS-protected). One row per (endpoint, event)
 * LOGICAL delivery (section 15) -- valid states (section 16):
 * pending, delivering, delivered, retrying, failed, abandoned --
 * enforced by a DB CHECK constraint, transitions enforced by
 * App\Jobs\DeliverWebhookJob, never an arbitrary string assignment.
 * Real HTTP attempts are recorded separately in WebhookDeliveryAttempt
 * -- never inline on this row.
 *
 * @property string $id
 * @property string $school_id
 * @property string $webhook_endpoint_id
 * @property string $event_id
 * @property string $event_type
 * @property string $status
 * @property int $attempts
 */
class WebhookDelivery extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'processing_lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** @return HasMany<WebhookDeliveryAttempt, $this> */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['delivered', 'failed', 'abandoned'], true);
    }
}
