<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportRouteAssignment;
use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportRouteAssignment>
 */
class TransportRouteAssignmentFactory extends Factory
{
    protected $model = TransportRouteAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'route_id' => TransportRoute::factory(),
            'vehicle_id' => TransportVehicle::factory(),
            'driver_employee_id' => Employee::factory(),
            'status' => 'active',
            'starts_on' => now(),
            'ends_on' => null,
        ];
    }
}
