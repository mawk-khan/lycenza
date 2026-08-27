<?php

namespace Database\Factories;

use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryTitle>
 */
class LibraryTitleFactory extends Factory
{
    protected $model = LibraryTitle::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'title' => fake()->unique()->sentence(3),
            'author' => fake()->name(),
            'isbn' => null,
            'status' => 'active',
        ];
    }
}
