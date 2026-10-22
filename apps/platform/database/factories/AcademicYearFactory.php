<?php

namespace Database\Factories;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    protected $model = AcademicYear::class;

    public function definition(): array
    {
        // ORG.8G (ADR 0076 §65): deterministic. A placement may no longer end
        // after its year, so a random year (2024-2030) made every fixture that
        // ends a placement on a fixed date pass or fail by chance. Tests that
        // need another year pass explicit dates.
        $startYear = 2026;
        $startsOn = "{$startYear}-06-01";
        $endsOn = ($startYear + 1).'-05-31';

        return [
            'school_id' => School::factory(),
            'name' => "{$startYear}-".substr((string) ($startYear + 1), 2),
            'code' => "AY{$startYear}",
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => 'draft',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'closed']);
    }
}
