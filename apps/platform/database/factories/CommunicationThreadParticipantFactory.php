<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationThreadParticipant> */
class CommunicationThreadParticipantFactory extends Factory
{
    protected $model = CommunicationThreadParticipant::class;

    public function definition(): array
    {
        return [
            'joined_at' => now(),
            'muted' => false,
            'archived' => false,
        ];
    }
}
