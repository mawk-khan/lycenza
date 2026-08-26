<?php

namespace Database\Factories;

use App\Domain\Documents\Infrastructure\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 *
 * Deliberately does NOT default `school_id` or any of employee_id/
 * student_id/guardian_id -- same reasoning as EmployeeDocumentFactory:
 * the exactly-one-owner CHECK constraint means a caller must always
 * supply exactly one owner explicitly (via `->for($employee)`/
 * `->for($student)`/`->for($guardian)` or explicit attributes), never
 * a factory-picked default. Never creates real filesystem/object
 * content -- `storage_path` is a fake, well-formed relative value
 * only.
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'classification_tier' => 'internal',
            'storage_disk' => 'local',
            'storage_path' => 'documents/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'uploaded_by_user_id' => null,
            'uploaded_at' => now(),
            'status' => 'active',
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => ['classification_tier' => 'public']);
    }

    public function sensitive(): static
    {
        return $this->state(fn () => ['classification_tier' => 'sensitive']);
    }

    public function highlySensitive(): static
    {
        return $this->state(fn () => ['classification_tier' => 'highly_sensitive']);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'archived']);
    }
}
