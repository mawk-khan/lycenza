<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\EnrollmentRolloverSubjectMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentRolloverSubjectMapping>
 *
 * Deliberately does NOT default any of `school_id`/`plan_id`/
 * `source_subject_offering_id`/`target_subject_offering_id` -- every
 * reference must belong to the SAME School as the plan (and, per the
 * mutation service, the plan's declared source/target AcademicYear),
 * matching EnrollmentRolloverMappingFactory's exact precedent. Tests
 * build the full graph via Tests\Concerns\CreatesTenancyFixtures and
 * pass the resulting ids explicitly. `target_subject_offering_id` is
 * simply omitted (stays NULL) to build the explicit-omit fixture state.
 */
class EnrollmentRolloverSubjectMappingFactory extends Factory
{
    protected $model = EnrollmentRolloverSubjectMapping::class;

    public function definition(): array
    {
        return [];
    }
}
