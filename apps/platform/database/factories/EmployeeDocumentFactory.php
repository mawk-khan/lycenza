<?php

namespace Database\Factories;

use App\Domain\HR\Infrastructure\EmployeeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDocument>
 *
 * Deliberately does NOT default `school_id`/`employee_id` -- same
 * reasoning as EmployeeAddressFactory. Never creates real filesystem/
 * object content -- `storage_path` is a fake, well-formed relative
 * value only; no `Storage` facade call occurs anywhere in this
 * factory, matching EmployeeDocumentService's own metadata-only
 * boundary.
 */
class EmployeeDocumentFactory extends Factory
{
    protected $model = EmployeeDocument::class;

    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(['id_proof', 'employment_contract', 'appointment_letter', 'qualification_evidence', 'other']),
            'classification_tier' => 'restricted',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'uploaded_by_user_id' => null,
            'uploaded_at' => now(),
            'issued_on' => null,
            'expires_on' => null,
            'status' => 'active',
        ];
    }

    public function highlySensitive(): static
    {
        return $this->state(fn () => ['classification_tier' => 'highly_sensitive']);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'archived']);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'issued_on' => now()->subYears(3),
            'expires_on' => now()->subDays(10),
        ]);
    }
}
