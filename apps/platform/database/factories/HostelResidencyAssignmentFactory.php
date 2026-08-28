<?php

namespace Database\Factories;

use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostelResidencyAssignment>
 */
class HostelResidencyAssignmentFactory extends Factory
{
    protected $model = HostelResidencyAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'hostel_bed_id' => HostelBed::factory(),
            'status' => 'active',
            'starts_on' => now(),
            'ends_on' => null,
        ];
    }
}
