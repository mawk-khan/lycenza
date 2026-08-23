<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationDeliveryTimingPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationDeliveryTimingPolicy> */
class CommunicationDeliveryTimingPolicyFactory extends Factory
{
    protected $model = CommunicationDeliveryTimingPolicy::class;

    public function definition(): array
    {
        return [
            'channel' => 'email',
            'enabled' => true,
            'quiet_hours_start' => '20:00:00',
            'quiet_hours_end' => '07:00:00',
        ];
    }
}
