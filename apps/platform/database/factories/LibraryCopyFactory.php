<?php

namespace Database\Factories;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryCopy>
 */
class LibraryCopyFactory extends Factory
{
    protected $model = LibraryCopy::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'library_title_id' => LibraryTitle::factory(),
            'campus_id' => null,
            'code' => strtoupper(fake()->unique()->bothify('LIB-#####')),
            'status' => 'active',
        ];
    }
}
