<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationThread>
 *
 * Deliberately does NOT default `school_id`/`created_by_user_id` --
 * both must be created inside the owning School's TenantContext, same
 * reasoning as SectionFactory's docblock.
 */
class CommunicationThreadFactory extends Factory
{
    protected $model = CommunicationThread::class;

    public function definition(): array
    {
        return [
            'thread_type' => 'direct',
            'subject' => null,
            'status' => 'open',
            'last_activity_at' => now(),
        ];
    }
}
