<?php

namespace Database\Factories;

use App\Domain\Examinations\Infrastructure\GradeScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeScale>
 *
 * Deliberately defaults NO `school_id` -- callers pass it explicitly
 * (or rely on `BelongsToSchool`'s auto-fill from `TenantContext`),
 * mirroring every other tenant-owned factory in this codebase.
 */
class GradeScaleFactory extends Factory
{
    protected $model = GradeScale::class;

    public function definition(): array
    {
        return [
            'code' => 'GS'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Grade Scale '.fake()->word(),
            'status' => GradeScale::STATUS_DRAFT,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => GradeScale::STATUS_ACTIVE]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => GradeScale::STATUS_INACTIVE]);
    }
}
