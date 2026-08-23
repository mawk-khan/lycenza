<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationAnnouncement>
 *
 * Deliberately does NOT default `school_id`/`created_by_user_id` --
 * both must be created inside the owning School's TenantContext, same
 * reasoning as CommunicationThreadFactory's docblock.
 */
class CommunicationAnnouncementFactory extends Factory
{
    protected $model = CommunicationAnnouncement::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'priority' => 'normal',
            'status' => 'draft',
            'audience_type' => 'school_wide',
        ];
    }
}
