<?php

namespace Database\Factories;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryLoan>
 */
class LibraryLoanFactory extends Factory
{
    protected $model = LibraryLoan::class;

    public function definition(): array
    {
        $checkedOutAt = now();

        return [
            'school_id' => School::factory(),
            'library_copy_id' => LibraryCopy::factory(),
            'student_id' => Student::factory(),
            'status' => 'active',
            'checked_out_at' => $checkedOutAt,
            'due_at' => $checkedOutAt->copy()->addDays(14),
            'checked_in_at' => null,
        ];
    }
}
