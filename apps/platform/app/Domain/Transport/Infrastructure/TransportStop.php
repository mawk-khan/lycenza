<?php

namespace App\Domain\Transport\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\TransportStopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ordered pickup/drop-off point on exactly one Route. See
 * docs/modules/TRANSPORT.md "Stop integrity" -- this table's
 * `unique(id, route_id, school_id)` is what lets
 * TransportStudentAssignment's pickup/dropoff Stop references be
 * database-enforced composite FKs, not just controller validation.
 *
 * @property string $id
 * @property string $school_id
 * @property string $route_id
 * @property string $name
 * @property int $sequence
 * @property string|null $address
 * @property string $status active|inactive
 */
class TransportStop extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'transport_stops';

    protected $fillable = ['school_id', 'route_id', 'name', 'sequence', 'address', 'status'];

    protected static function newFactory(): TransportStopFactory
    {
        return TransportStopFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<TransportRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }
}
