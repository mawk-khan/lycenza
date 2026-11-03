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
            // HR.2A: the closed categories (identity_document needs the highly_sensitive state).
            'category' => fake()->randomElement(['employment_contract', 'appointment_letter', 'qualification_evidence', 'experience_evidence', 'certification_evidence']),
            'classification_tier' => 'restricted',
            // HR.2A: a record references a governed Document (`document_id`, set by the fixture), never a raw path.
            'storage_disk' => null,
            'storage_path' => null,
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
        return $this->state(fn () => ['classification_tier' => 'highly_sensitive', 'category' => 'identity_document']);
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
