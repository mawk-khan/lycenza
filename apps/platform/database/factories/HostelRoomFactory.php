<?php

namespace Database\Factories;

use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostelRoom>
 */
class HostelRoomFactory extends Factory
{
    protected $model = HostelRoom::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'hostel_id' => Hostel::factory(),
            'code' => strtoupper(fake()->unique()->bothify('R-###')),
            'floor_or_block' => null,
            'status' => 'active',
        ];
    }
}
