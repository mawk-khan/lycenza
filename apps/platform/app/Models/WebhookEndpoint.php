<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned data (RLS-protected). See the migration's docblock,
 * App\Support\Webhooks\WebhookSigner, App\Support\Webhooks\SsrfSafeUrlValidator.
 * Event subscriptions are a separate concept -- see WebhookSubscription.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $url
 * @property string $secret_encrypted
 * @property string|null $previous_secret_encrypted
 * @property string $status
 */
class WebhookEndpoint extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Laravel's built-in encrypted cast (APP_KEY-based
            // AES-256-CBC) -- the raw secret is only ever visible
            // in-memory to code that reads this attribute; never
            // logged, never audited (see AuditRecorder usage
            // throughout this module).
            'secret_encrypted' => 'encrypted',
            'previous_secret_encrypted' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<WebhookSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(WebhookSubscription::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
