<?php

namespace Database\Factories;

use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationDeliveryPolicyDecision> */
class CommunicationDeliveryPolicyDecisionFactory extends Factory
{
    protected $model = CommunicationDeliveryPolicyDecision::class;

    public function definition(): array
    {
        return [
            'channel' => 'email',
            'reason' => 'recipient_preference_disabled',
        ];
    }
}
