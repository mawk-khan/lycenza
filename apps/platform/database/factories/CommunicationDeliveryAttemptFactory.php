<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationDeliveryAttempt> */
class CommunicationDeliveryAttemptFactory extends Factory
{
    protected $model = CommunicationDeliveryAttempt::class;

    public function definition(): array
    {
        return [
            'attempt_number' => 1,
            'started_at' => now(),
            'completed_at' => now(),
            'outcome' => 'success',
        ];
    }
}
