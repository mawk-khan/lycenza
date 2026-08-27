<?php

namespace App\Domain\Transport\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\TransportVehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A School Transport vehicle. `capacity` is informational-only in this
 * checkpoint -- see docs/modules/TRANSPORT.md "Vehicle capacity
 * invariant". `registration_number` is deliberately NOT normalized
 * like `code` -- it is real-world reference data, not a School-chosen
 * identifier.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $campus_id
 * @property string $code
 * @property string $registration_number
 * @property int|null $capacity
 * @property string $status active|inactive
 */
class TransportVehicle extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'transport_vehicles';

    protected $fillable = ['school_id', 'campus_id', 'code', 'registration_number', 'capacity', 'status'];

    protected static function newFactory(): TransportVehicleFactory
    {
        return TransportVehicleFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return HasMany<TransportRouteAssignment, $this> */
    public function routeAssignments(): HasMany
    {
        return $this->hasMany(TransportRouteAssignment::class, 'vehicle_id');
    }

    /** @return HasOne<TransportRouteAssignment, $this> */
    public function activeRouteAssignment(): HasOne
    {
        return $this->hasOne(TransportRouteAssignment::class, 'vehicle_id')->where('status', 'active');
    }
}
