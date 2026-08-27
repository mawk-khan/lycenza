<?php

namespace App\Domain\Transport\Infrastructure;

use App\Domain\HR\Infrastructure\Employee;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\TransportRouteAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The historical Route <-> Vehicle <-> Driver operational
 * configuration. Never write directly; the sole sanctioned write path
 * is App\Domain\Transport\Application\TransportRouteAssignmentService.
 * See docs/modules/TRANSPORT.md "Route operational assignment
 * decision".
 *
 * @property string $id
 * @property string $school_id
 * @property string $route_id
 * @property string $vehicle_id
 * @property string $driver_employee_id
 * @property string $status active|ended
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class TransportRouteAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'transport_route_assignments';

    protected $fillable = ['school_id', 'route_id', 'vehicle_id', 'driver_employee_id', 'status', 'starts_on', 'ends_on'];

    protected static function newFactory(): TransportRouteAssignmentFactory
    {
        return TransportRouteAssignmentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'datetime',
            'ends_on' => 'datetime',
        ];
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

    /** @return BelongsTo<TransportVehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'vehicle_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_employee_id');
    }
}
