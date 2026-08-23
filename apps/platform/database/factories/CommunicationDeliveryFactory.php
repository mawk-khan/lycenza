<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationDelivery> */
class CommunicationDeliveryFactory extends Factory
{
    protected $model = CommunicationDelivery::class;

    public function definition(): array
    {
        return [
            'channel' => 'in_app',
            'status' => 'pending',
            'attempts' => 0,
            'queued_at' => now(),
        ];
    }
}
