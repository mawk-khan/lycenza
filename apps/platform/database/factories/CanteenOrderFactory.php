<?php

namespace Database\Factories;

use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenOrder>
 *
 * Deliberately LAZY relation references -- see CanteenOutletFactory's
 * docblock for why (RLS-safety under an already-active TenantContext).
 * A bare `CanteenOrder::factory()->create()` produces a syntactically
 * valid PENDING row for exercising `canteen_orders`' own constraints/
 * RLS in isolation -- a test needing a real fulfilled/cancelled Order
 * with real downstream Inventory/Fees effects must go through the
 * actual `App\Domain\Canteen\Application\CanteenOrderService` instead
 * (`Tests\Concerns\CreatesCanteenFixtures`).
 */
class CanteenOrderFactory extends Factory
{
    protected $model = CanteenOrder::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'outlet_id' => CanteenOutlet::factory(),
            'inventory_location_id' => InventoryLocation::factory(),
            'status' => 'pending',
            'total_amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'INR',
            'charge_id' => null,
            'placed_at' => now(),
            'fulfilled_at' => null,
            'cancelled_at' => null,
        ];
    }
}
