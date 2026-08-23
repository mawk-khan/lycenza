<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 *
 * Deliberately does NOT default `academic_year_id`/`campus_id`/
 * `grade_level_id`/`school_id` -- a Section spans three independent
 * parents that must all belong to the SAME School, which a single
 * factory relationship default cannot guarantee cleanly under RLS
 * (each parent's own creation must happen inside that School's
 * TenantContext). Tests build the full graph via
 * Tests\Concerns\CreatesTenancyFixtures's Phase 0D helpers and pass the
 * resulting ids explicitly.
 */
class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [
            'name' => 'A',
            'code' => 'A',
            'capacity' => fake()->numberBetween(20, 45),
            'status' => 'active',
        ];
    }
}
