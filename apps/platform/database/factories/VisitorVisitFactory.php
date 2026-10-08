<?php

namespace Database\Factories;

use App\Domain\Visitor\Infrastructure\Visitor;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Models\Campus;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<VisitorVisit>
 */
class VisitorVisitFactory extends Factory
{
    protected $model = VisitorVisit::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'visitor_id' => Visitor::factory(),
            'campus_id' => Campus::factory(),
            'host_employee_id' => null,
            'purpose' => fake()->sentence(),
            'gate_pass_number' => null,
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checked_out_at' => null,
        ];
    }

    /**
     * S3: a completed Visit. The check-out is derived from the row's own
     * check-in instant -- never a second clock read -- so it can never precede
     * it (`visitor_visits_checkout_after_checkin_check`) however the clock
     * moves, and both survive the column's whole-second precision in order.
     */
    public function checkedOut(int $minutes = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'checked_out',
            'checked_out_at' => Carbon::make($attributes['checked_in_at'])?->addMinutes($minutes),
        ]);
    }
}
