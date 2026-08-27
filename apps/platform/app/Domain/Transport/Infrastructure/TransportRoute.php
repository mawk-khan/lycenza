<?php

namespace App\Domain\Transport\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\TransportRouteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Transport route. See docs/modules/TRANSPORT.md "Campus ownership
 * decision" -- `campus_id` is optional; a null value means School-wide
 * shared Transport, not an error.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $campus_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $status active|inactive
 */
class TransportRoute extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'transport_routes';

    protected $fillable = ['school_id', 'campus_id', 'code', 'name', 'description', 'status'];

    protected static function newFactory(): TransportRouteFactory
    {
        return TransportRouteFactory::new();
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

    /** @return HasMany<TransportStop, $this> */
    public function stops(): HasMany
    {
        return $this->hasMany(TransportStop::class, 'route_id');
    }

    /** @return HasMany<TransportRouteAssignment, $this> */
    public function routeAssignments(): HasMany
    {
        return $this->hasMany(TransportRouteAssignment::class, 'route_id');
    }

    /** @return HasOne<TransportRouteAssignment, $this> */
    public function activeRouteAssignment(): HasOne
    {
        return $this->hasOne(TransportRouteAssignment::class, 'route_id')->where('status', 'active');
    }

    /** @return HasMany<TransportStudentAssignment, $this> */
    public function studentAssignments(): HasMany
    {
        return $this->hasMany(TransportStudentAssignment::class, 'route_id');
    }
}
