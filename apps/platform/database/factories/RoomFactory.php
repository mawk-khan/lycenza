<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'campus_id' => Campus::factory(),
            'school_id' => function (array $attributes) {
                return Campus::find($attributes['campus_id'])->school_id;
            },
            'name' => 'Room '.fake()->unique()->numberBetween(100, 999),
            'code' => strtoupper(fake()->unique()->lexify('RM???')),
            'room_type' => 'classroom',
            'capacity' => fake()->numberBetween(20, 60),
            'status' => 'active',
        ];
    }
}
