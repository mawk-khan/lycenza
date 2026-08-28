<?php

namespace Database\Factories;

use App\Domain\Visitor\Infrastructure\Visitor;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Models\Campus;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
