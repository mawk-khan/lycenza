<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Central/platform data. The authoritative (unique-constraint-backed)
 * dedup record for "consumer X already processed event Y" -- section 11.
 *
 * @property string $id
 * @property string $consumer_name
 * @property string $event_id
 * @property string|null $school_id
 */
class EventConsumerReceipt extends Model
{
    use GeneratesUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
