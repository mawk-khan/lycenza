<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentRolloverMapping>
 *
 * Deliberately does NOT default any of `school_id`/`plan_id`/
 * `source_grade_level_id`/`source_section_id`/`target_grade_level_id`/
 * `target_section_id` -- every reference must belong to the SAME
 * School as the plan, matching StudentEnrollmentFactory/SectionFactory's
 * exact precedent. Tests build the full graph via
 * Tests\Concerns\CreatesTenancyFixtures and pass the resulting ids
 * explicitly.
 */
class EnrollmentRolloverMappingFactory extends Factory
{
    protected $model = EnrollmentRolloverMapping::class;

    public function definition(): array
    {
        return [];
    }
}
