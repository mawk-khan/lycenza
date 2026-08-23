<?php

namespace Database\Factories;

use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentGuardianRelationship>
 */
class StudentGuardianRelationshipFactory extends Factory
{
    protected $model = StudentGuardianRelationship::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'relationship_type' => RelationshipType::Other,
            'is_primary' => false,
            'is_legal_guardian' => false,
            'is_emergency_contact' => false,
            'is_authorized_pickup' => false,
        ];
    }
}
