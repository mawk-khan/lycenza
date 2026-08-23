<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationTemplate>
 *
 * Deliberately does NOT default `school_id`/`created_by_user_id` --
 * both must be created inside the owning School's TenantContext, same
 * reasoning as CommunicationAnnouncementFactory's docblock.
 */
class CommunicationTemplateFactory extends Factory
{
    protected $model = CommunicationTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'template_type' => 'announcement',
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'status' => 'active',
        ];
    }
}
