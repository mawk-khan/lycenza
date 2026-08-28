<?php

namespace Database\Factories;

use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenOutlet>
 *
 * Deliberately LAZY relation references (never an eager nested
 * `->create()` inside `definition()`) -- mirrors
 * `Database\Factories\InventoryStockBalanceFactory`'s exact pattern,
 * NOT `ChargeFactory`'s eager-nested style: an eager nested create
 * inside `definition()` fixes its OWN school_id at definition-time,
 * which breaks the moment this factory is invoked from inside an
 * ALREADY-active `TenantContext::withSchool()` for a DIFFERENT School
 * (RLS then rejects the nested insert). Every RLS-aware test fixture
 * helper (`Tests\Concerns\CreatesCanteenFixtures`) always explicitly
 * overrides every foreign key here, so these lazy defaults only ever
 * resolve for a genuinely standalone, unwrapped factory call.
 */
class CanteenOutletFactory extends Factory
{
    protected $model = CanteenOutlet::class;

    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 100000);

        return [
            'school_id' => School::factory(),
            'campus_id' => null,
            'inventory_location_id' => InventoryLocation::factory(),
            'code' => "OUT{$sequence}",
            'name' => fake()->words(2, true).' Canteen',
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
