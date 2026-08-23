<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationMessage> */
class CommunicationMessageFactory extends Factory
{
    protected $model = CommunicationMessage::class;

    public function definition(): array
    {
        return [
            'message_type' => 'text',
            'body' => fake()->sentence(),
            'priority' => 'normal',
            'status' => 'sent',
        ];
    }
}
