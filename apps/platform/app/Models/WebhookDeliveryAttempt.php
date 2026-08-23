<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned, APPEND-ONLY data (RLS-protected + DB-privilege
 * enforced, see the migration). One row per REAL HTTP attempt for a
 * logical WebhookDelivery (section 17) -- written once, fully
 * populated, after the attempt completes (never claimed-then-updated,
 * so the append-only DB privilege revocation is compatible with how
 * this row is written -- see App\Jobs\DeliverWebhookJob).
 *
 * @property string $id
 * @property string $school_id
 * @property string $webhook_delivery_id
 * @property int $attempt_number
 * @property string $outcome
 */
class WebhookDeliveryAttempt extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
