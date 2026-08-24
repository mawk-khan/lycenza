<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationAttachment>
 *
 * Deliberately does NOT default `school_id`/`communication_announcement_id`/
 * `created_by_user_id` -- all three must be created inside the owning
 * School's TenantContext, same reasoning as CommunicationAnnouncementFactory's
 * docblock. `storage_path`/`checksum_sha256` here are synthetic test
 * values only -- real rows are only ever produced by
 * App\Domain\Communications\Application\CommunicationAttachmentService::upload(),
 * which is what every genuine upload/validation test exercises instead
 * of this factory.
 */
class CommunicationAttachmentFactory extends Factory
{
    protected $model = CommunicationAttachment::class;

    public function definition(): array
    {
        return [
            'storage_disk' => 'local',
            'storage_path' => 'communications/attachments/'.fake()->uuid().'.pdf',
            'original_filename' => 'Report Card.pdf',
            'safe_display_name' => 'Report Card.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 500_000),
            'checksum_sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}
