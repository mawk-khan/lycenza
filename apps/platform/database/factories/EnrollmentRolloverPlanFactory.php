<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentRolloverPlan>
 *
 * Deliberately does NOT default `school_id`/`source_academic_year_id`/
 * `target_academic_year_id` -- both AcademicYears must belong to the
 * SAME School as the plan, which a single factory relationship default
 * cannot guarantee cleanly under RLS, matching
 * StudentEnrollmentFactory's exact precedent. Tests build the full
 * graph via Tests\Concerns\CreatesTenancyFixtures and pass the
 * resulting ids explicitly.
 */
class EnrollmentRolloverPlanFactory extends Factory
{
    protected $model = EnrollmentRolloverPlan::class;

    public function definition(): array
    {
        return [
            'status' => 'draft',
            'configuration_version' => 1,
        ];
    }
}
