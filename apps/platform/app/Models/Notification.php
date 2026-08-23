<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned data (RLS-protected). Channel-agnostic notification
 * intent -- see the migration's docblock and
 * App\Support\Notifications\NotificationDispatcher.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $recipient_user_id
 * @property string $channel
 * @property string $template_key
 * @property array $payload
 * @property string $status
 */
class Notification extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
