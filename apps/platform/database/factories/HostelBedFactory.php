<?php

namespace Database\Factories;

use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostelBed>
 */
class HostelBedFactory extends Factory
{
    protected $model = HostelBed::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'hostel_room_id' => HostelRoom::factory(),
            'code' => strtoupper(fake()->unique()->bothify('B-#')),
            'status' => 'active',
        ];
    }
}
