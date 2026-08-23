<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned data (RLS-protected). Links a School's webhook endpoint
 * to one event type it wants delivered (Phase 0C.3 section 11) -- a
 * distinct concept/lifecycle from the endpoint itself (section 4).
 *
 * @property string $id
 * @property string $school_id
 * @property string $webhook_endpoint_id
 * @property string $event_type
 * @property bool $enabled
 */
class WebhookSubscription extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
