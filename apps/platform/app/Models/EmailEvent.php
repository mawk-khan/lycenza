<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.9A (ADR 0055 section 11): one NORMALIZED provider event.
 * Platform data without RLS: no School, no address, no raw payload --
 * provider, event key, provider message id, closed type/bounce class,
 * timestamps and the processing result. The School is found later from
 * the stored message (email_provider_references), never from the event.
 *
 * @property string $id
 * @property string $provider
 * @property string $event_key
 * @property string $type
 * @property string|null $bounce_class
 * @property string|null $provider_message_id
 * @property Carbon|null $occurred_at
 * @property Carbon $received_at
 * @property string|null $email_message_id
 * @property string $result
 * @property Carbon|null $processed_at
 */
class EmailEvent extends Model
{
    use GeneratesUuidV7;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
