<?php

namespace Database\Factories;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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

    /**
     * A returned Loan. The return is derived from the row's own checkout
     * instant -- never a second clock read -- so it can never precede it
     * (`library_loans_checkin_after_checkout_check`) however the clock moves,
     * and both survive the columns' whole-second precision in order.
     */
    public function returned(int $days = 7): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'returned',
            'checked_in_at' => Carbon::make($attributes['checked_out_at'])?->addDays($days),
        ]);
    }
}
