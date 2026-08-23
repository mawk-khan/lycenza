<?php

namespace App\Domain\Communications\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationDeliveryTimingPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 5A.9 -- a School's explicit quiet-hours window for one
 * secondary transport channel. `quiet_hours_start`/`quiet_hours_end`
 * are School-local wall-clock strings ("HH:MM:SS", as Postgres `time`
 * columns come back from PDO) -- never a UTC offset. No row for a
 * (school, channel) pair, or `enabled = false`, means "send
 * immediately," identical to pre-5A.9 behavior.
 *
 * @use HasFactory<CommunicationDeliveryTimingPolicyFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $channel
 * @property bool $enabled
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property bool $emergency_bypass_allowed
 */
class CommunicationDeliveryTimingPolicy extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'channel', 'enabled', 'quiet_hours_start', 'quiet_hours_end', 'emergency_bypass_allowed'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'emergency_bypass_allowed' => 'boolean',
        ];
    }

    protected static function newFactory(): CommunicationDeliveryTimingPolicyFactory
    {
        return CommunicationDeliveryTimingPolicyFactory::new();
    }
}
