<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentRolloverItem>
 *
 * Deliberately does NOT default `school_id`/`plan_id`/`student_id`/
 * `source_enrollment_id` -- every reference must belong to the SAME
 * School (and `source_enrollment_id` must belong to the exact
 * `student_id`), matching StudentEnrollmentFactory's exact precedent.
 * Tests build the full graph via Tests\Concerns\CreatesTenancyFixtures
 * and pass the resulting ids explicitly.
 */
class EnrollmentRolloverItemFactory extends Factory
{
    protected $model = EnrollmentRolloverItem::class;

    public function definition(): array
    {
        return [
            'decision' => 'undecided',
        ];
    }
}
