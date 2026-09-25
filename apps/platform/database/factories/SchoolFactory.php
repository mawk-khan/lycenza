<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        $name = fake()->company().' School';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
            'default_locale' => 'en',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    /**
     * Phase 0N.9 (ADR 0047): created, never activated. The definition
     * above stays explicitly `active` -- ordinary tests need an
     * operational School and must not rely on the column default, which
     * is now `provisioning`.
     */
    public function provisioning(): static
    {
        return $this->state(fn () => ['status' => 'provisioning']);
    }
}
