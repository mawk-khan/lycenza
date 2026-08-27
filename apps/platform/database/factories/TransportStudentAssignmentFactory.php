<?php

namespace Database\Factories;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportStudentAssignment>
 */
class TransportStudentAssignmentFactory extends Factory
{
    protected $model = TransportStudentAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'route_id' => TransportRoute::factory(),
            'pickup_stop_id' => null,
            'dropoff_stop_id' => null,
            'status' => 'active',
            'starts_on' => now(),
            'ends_on' => null,
        ];
    }
}
